<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Audit;
use App\Support\IdentityOnboarding;
use App\Support\InstitutionAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MembershipController extends Controller
{
    public function index(Request $request, Institution $institution): View
    {
        InstitutionAccess::authorize($institution, 'members.view');
        $members = $institution->memberships()->with('user', 'role')
            ->when($request->filled('q'), fn ($query) => $query->whereHas('user', fn ($users) => $users->where(fn ($search) => $search->where('name', 'like', '%'.$request->string('q').'%')->orWhere('email', 'like', '%'.$request->string('q').'%'))))
            ->latest()->paginate(15)->withQueryString();

        return view('institution.members', compact('institution', 'members') + ['roles' => Role::where('scope', 'institution')->get()]);
    }

    public function store(Request $request, Institution $institution, IdentityOnboarding $onboarding): RedirectResponse
    {
        InstitutionAccess::authorize($institution, 'members.create');
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'],
            'role_id' => ['required', 'integer', 'exists:roles,id'], 'identifier' => ['nullable', 'string', 'max:100', Rule::unique('memberships')->where('institution_id', $institution->id)]]);
        $member = $onboarding->create($institution, $data);

        return redirect()->route('members.edit', [$institution, $member])->with('status', 'Invitation prepared. Membership is pending acceptance. Local log/array mail does not deliver to an inbox; an authorised operator can provide a private activation handoff.');
    }

    public function edit(Institution $institution, Membership $member): View
    {
        InstitutionAccess::scopedMember($institution, $member);
        abort_unless(InstitutionAccess::allows($institution, 'members.edit') || InstitutionAccess::allows($institution, 'members.permissions'), 403);
        $member->load('user', 'role');
        $overrides = DB::table('membership_permissions')->join('permissions', 'permissions.id', '=', 'permission_id')->where('membership_id', $member->id)->pluck('allowed', 'name')->all();

        return view('institution.member', compact('institution', 'member', 'overrides') + [
            'roles' => Role::where('scope', 'institution')->get(),
            'permissions' => Permission::where('scope', 'institution')->orderBy('group')->get(),
            'effective' => InstitutionAccess::effective($member),
        ]);
    }

    public function update(Request $request, Institution $institution, Membership $member, IdentityOnboarding $onboarding): RedirectResponse
    {
        InstitutionAccess::scopedMember($institution, $member);
        InstitutionAccess::authorize($institution, 'members.edit');
        abort_if(! InstitutionAccess::system() && $member->role->name === 'Institution Administrator', 403);
        $data = $request->validate(['role_id' => ['required', 'integer', 'exists:roles,id'], 'status' => ['required', Rule::in(['active', 'suspended', 'pending'])],
            'identifier' => ['nullable', 'string', 'max:100', Rule::unique('memberships')->where('institution_id', $institution->id)->ignore($member->id)],
            'account_status' => ['nullable', Rule::in(['active', 'suspended'])]]);
        if ($data['role_id'] != $member->role_id) {
            InstitutionAccess::authorize($institution, 'members.roles');
            $onboarding->authorizeRole($institution, Role::findOrFail($data['role_id']));
        }
        if ($request->filled('account_status')) {
            InstitutionAccess::requireSystem();
        }
        DB::transaction(function () use ($data, $institution, $member, $onboarding): void {
            Institution::whereKey($institution->id)->lockForUpdate()->firstOrFail();
            $member = Membership::with('user', 'role')->whereKey($member->id)->lockForUpdate()->firstOrFail();
            if ($member->status === 'pending' && $data['status'] !== 'pending') {
                abort(422, 'Pending memberships must be accepted through their one-time invitation.');
            }
            if ($member->status !== 'pending' && $data['status'] === 'pending') {
                abort(422, 'An accepted membership cannot be made pending again.');
            }
            $onboarding->protectFinalAdministrator($institution, $member, (int) $data['role_id'], $data['status']);
            if (($data['account_status'] ?? null) === 'suspended') {
                foreach ($member->user->memberships()->with('role', 'user', 'institution')->orderBy('institution_id')->get() as $other) {
                    Institution::whereKey($other->institution_id)->lockForUpdate()->firstOrFail();
                    $onboarding->protectFinalAdministrator($other->institution, $other, $other->role_id, 'suspended');
                }
                abort_if($member->user->roles()->where('name', 'System Administrator')->exists(), 422, 'System Administrator account suspension requires a separate platform security review.');
            }
            if (! empty($data['account_status'])) {
                abort_if($member->user->status === 'pending', 422, 'Use activation for a pending identity.');
                $member->user->forceFill(['status' => $data['account_status']])->save();
            }
            if ((int) $data['role_id'] !== (int) $member->role_id) {
                DB::table('membership_permissions')->where('membership_id', $member->id)->delete();
                DB::table('class_assignments')->where('membership_id', $member->id)->delete();
            }
            $member->update(collect($data)->except('account_status')->all());
            Audit::record('membership.updated', $member, $institution->id, ['role_id' => $member->role_id, 'status' => $member->status, 'account_status' => $data['account_status'] ?? null]);
        });

        return back()->with('status', 'Membership saved. Global identity details were not changed.');
    }

    public function permissions(Request $request, Institution $institution, Membership $member): RedirectResponse
    {
        InstitutionAccess::scopedMember($institution, $member);
        InstitutionAccess::authorize($institution, 'members.permissions');
        abort_if($member->role->name === 'Institution Administrator', 403, 'Administrator baseline rights are protected. Manage the appointment rather than overriding its permissions.');
        $data = $request->validate(['overrides' => ['nullable', 'array'], 'overrides.*' => [Rule::in(['inherit', 'grant', 'deny'])]]);
        $catalogue = Permission::where('scope', 'institution')->get()->keyBy('id');
        $actor = InstitutionAccess::membership($institution);
        $authority = InstitutionAccess::system() ? $catalogue->pluck('name')->all() : InstitutionAccess::effective($actor);
        foreach ($data['overrides'] ?? [] as $id => $mode) {
            abort_unless($catalogue->has($id) && in_array($catalogue[$id]->name, $authority, true), 403);
        }
        DB::transaction(function () use ($data, $member, $institution): void {
            Membership::whereKey($member->id)->lockForUpdate()->firstOrFail();
            foreach ($data['overrides'] ?? [] as $id => $mode) {
                $query = DB::table('membership_permissions')->where('membership_id', $member->id)->where('permission_id', $id);
                if ($mode === 'inherit') {
                    $query->delete();
                } else {
                    DB::table('membership_permissions')->updateOrInsert(['membership_id' => $member->id, 'permission_id' => $id], ['allowed' => $mode === 'grant']);
                }
            }
            Audit::record('membership.permissions_changed', $member, $institution->id, ['overrides' => $data['overrides'] ?? []]);
        });

        return back()->with('status', 'Scoped permissions saved. Effective rights are shown below.');
    }

    public function resend(Institution $institution, Membership $member, IdentityOnboarding $onboarding): RedirectResponse
    {
        InstitutionAccess::authorize($institution, 'members.create');
        InstitutionAccess::scopedMember($institution, $member);
        abort_unless($member->status === 'pending', 422);
        $onboarding->invite($member);

        return back()->with('status', 'Invitation reissued; older links are invalid. Consult the private mail channel in development.');
    }
}

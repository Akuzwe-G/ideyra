<?php

namespace App\Http\Controllers;

use App\Mail\ApplicationUpdateMail;
use App\Models\AuditEntry;
use App\Models\Institution;
use App\Models\JoinApplication;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\ApplicationWorkflow;
use App\Support\Audit;
use App\Support\DashboardAnalytics;
use App\Support\InstitutionAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SystemController extends Controller
{
    public function dashboard(Request $request, DashboardAnalytics $analytics): View
    {
        InstitutionAccess::requireSystem();

        $filters = $analytics->filters($request);

        $totals = ['Total institutions' => Institution::count(), 'Active institutions' => Institution::where('status', 'active')->count(), 'Total users' => User::count(), 'Total applications' => JoinApplication::count(), 'Pending applications' => JoinApplication::whereIn('status', ['submitted', 'under_review', 'more_information_requested'])->count(), 'Approved applications' => JoinApplication::where('status', 'approved')->count()];

        return view('system.dashboard', ['filters' => $filters, 'analytics' => $analytics->system($filters), 'totals' => $totals, 'pendingInstitutions' => Institution::whereIn('status', ['draft', 'pending_activation'])->count(), 'recentOnboarded' => Institution::whereNotNull('approved_at')->latest('approved_at')->limit(5)->get(['id', 'name', 'status', 'approved_at'])]);
    }

    public function users(Request $request): View
    {
        InstitutionAccess::requireSystem();
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['active', 'pending', 'suspended'])]]);
        $users = User::with('roles')->withCount('memberships')->when($request->filled('q'), fn ($query) => $query->where(fn ($search) => $search->where('name', 'like', '%'.$request->string('q').'%')->orWhere('email', 'like', '%'.$request->string('q').'%')))->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))->orderBy('name')->paginate(15)->withQueryString();

        return view('system.users', compact('users'));
    }

    public function applications(Request $request): View
    {
        InstitutionAccess::requireSystem();
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'institution' => ['nullable', 'string', 'max:100'], 'country' => ['nullable', 'string', 'max:100'], 'type' => ['nullable', Rule::in(['institution', 'student'])], 'status' => ['nullable', Rule::in(['submitted', 'under_review', 'more_information_requested', 'approved', 'rejected', 'onboarded'])], 'queue' => ['nullable', Rule::in(['pending'])]]);
        $query = JoinApplication::query();
        if ($request->query('queue') === 'pending') {
            $query->whereIn('status', ['submitted', 'under_review', 'more_information_requested']);
        }
        foreach (['type', 'status', 'country'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->string($field));
            }
        }
        foreach (['q' => 'search_tokens', 'institution' => 'institution_tokens'] as $input => $column) {
            if ($request->filled($input)) {
                $value = mb_substr(trim($request->string($input)), 0, 100);
                if ($input === 'q' && str_starts_with(strtoupper($value), 'IDEYRA-')) {
                    $query->where('reference', 'like', strtoupper($value).'%');
                } else {
                    foreach (preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY) as $word) {
                        $query->whereJsonContains($column, ApplicationWorkflow::hash($word));
                    }
                }
            }
        }

        return view('system.applications', ['applications' => $query->latest()->paginate(15)->withQueryString()]);
    }

    public function application(JoinApplication $application): View
    {
        InstitutionAccess::requireSystem();

        return view('system.application', compact('application') + ['onboarding' => Institution::where('application_id', $application->id)->first()]);
    }

    public function review(Request $request, JoinApplication $application, ApplicationWorkflow $workflow): RedirectResponse
    {
        InstitutionAccess::requireSystem();
        $data = $request->validate(['status' => ['required', Rule::in(['submitted', 'under_review', 'more_information_requested', 'approved', 'rejected', 'onboarded'])], 'note' => ['nullable', 'string', 'max:3000'], 'public_message' => ['required_if:status,more_information_requested', 'nullable', 'string', 'max:2000']]);
        $workflow->review($application, $data['status'], $data['note'] ?? null);
        if (! empty($data['public_message'])) {
            $application->refresh();
            try {
                Mail::to($application->payload['email'])->send(new ApplicationUpdateMail($application, $data['public_message']));
                $driver = config('mail.mailers.'.config('mail.default').'.transport');
                $application->update(['mail_attempted_at' => now(), 'mail_status' => in_array($driver, ['log', 'array'], true) ? ($driver === 'log' ? 'logged' : 'simulated') : 'unconfirmed']);
            } catch (\Throwable) {
                $application->update(['mail_attempted_at' => now(), 'mail_status' => 'failed']);
            }
        }

        return back()->with('status', 'Review recorded. Any applicant message was prepared separately from private notes; local log mail is not inbox delivery.');
    }

    public function audit(): View
    {
        InstitutionAccess::requireSystem();

        return view('system.audit', ['entries' => AuditEntry::latest('id')->paginate(25), 'institution' => null]);
    }

    public function institutionAudit(Institution $institution): View
    {
        InstitutionAccess::authorize($institution, 'audit.view');

        return view('system.audit', ['entries' => AuditEntry::where('institution_id', $institution->id)->latest('id')->paginate(25), 'institution' => $institution]);
    }

    public function roles(): View
    {
        InstitutionAccess::requireSystem();

        return view('system.roles', ['roles' => Role::orderBy('name')->get(), 'permissions' => Permission::orderBy('group')->orderBy('name')->get()]);
    }

    public function saveRole(Request $request, ?Role $role = null): RedirectResponse
    {
        InstitutionAccess::requireSystem();
        abort_if($role && in_array($role->name, ['System Administrator', 'Institution Administrator', 'Student', 'Lecturer / Teacher', 'Mentor / Supervisor'], true), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:100', Rule::notIn(['Platform Admin', 'Platform Administrator']), Rule::unique('roles')->ignore($role?->id)],
            'permissions' => ['nullable', 'array'], 'permissions.*' => [Rule::exists('permissions', 'name')->where('scope', 'institution')]]);
        DB::transaction(function () use ($role, $data): void {
            $role ??= new Role;
            $role->fill(['name' => $data['name'], 'scope' => 'institution', 'permissions' => $data['permissions'] ?? []])->save();
            Audit::record('role.definition_saved', $role);
        });

        return back()->with('status', 'Institutional role template saved.');
    }

    public function savePermission(Request $request): RedirectResponse
    {
        InstitutionAccess::requireSystem();
        $data = $request->validate(['name' => ['required', 'regex:/^custom\.[a-z][a-z0-9_.]{2,80}$/', 'unique:permissions,name'], 'group' => ['required', 'string', 'max:80']]);
        DB::transaction(function () use ($data): void {
            $permission = Permission::create($data + ['scope' => 'institution', 'implemented' => false]);
            Audit::record('permission.definition_created', $permission);
        });

        return back()->with('status', 'Future permission definition created. It authorises no unimplemented feature.');
    }

    public function settings(Request $request): View|RedirectResponse
    {
        InstitutionAccess::requireSystem();
        if ($request->isMethod('post')) {
            $data = $request->validate(['applications_open' => ['required', 'boolean']]);
            DB::transaction(function () use ($data): void {
                DB::table('platform_settings')->updateOrInsert(['key' => 'applications_open'], ['value' => json_encode((bool) $data['applications_open'])]);
                Audit::record('platform.configuration_updated', auth()->user(), null, ['applications_open' => (bool) $data['applications_open']]);
            });

            return back()->with('status', 'Application intake configuration saved.');
        }

        return view('system.settings', ['open' => json_decode(DB::table('platform_settings')->where('key', 'applications_open')->value('value') ?? 'true')]);
    }

    public function updatePermission(Request $request, Permission $permission): RedirectResponse
    {
        InstitutionAccess::requireSystem();
        $data = $request->validate(['group' => ['required', 'string', 'max:80']]);
        DB::transaction(function () use ($permission, $data): void {
            $permission->update($data);
            Audit::record('permission.definition_updated', $permission, null, $data);
        });

        return back()->with('status', 'Catalogue grouping updated. Capability keys and implementation flags remain protected.');
    }
}

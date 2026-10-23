<?php

namespace App\Support;

use App\Mail\IdentityInvitationMail;
use App\Models\IdentityInvitation;
use App\Models\Institution;
use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class IdentityOnboarding
{
    public function create(Institution $institution, array $data): Membership
    {
        InstitutionAccess::authorize($institution, 'members.create');
        $role = Role::findOrFail($data['role_id']);
        $this->authorizeRole($institution, $role);
        $membership = DB::transaction(function () use ($institution, $data, $role): Membership {
            Institution::whereKey($institution->id)->lockForUpdate()->firstOrFail();
            $user = User::where('email', mb_strtolower($data['email']))->first();
            if (! $user) {
                $user = User::create(['name' => $data['name'], 'email' => mb_strtolower($data['email']), 'password' => Str::random(64)]);
                $user->forceFill(['status' => 'pending', 'phone' => $data['phone'] ?? null])->save();
            }
            if ($user->status === 'suspended' || Membership::where('institution_id', $institution->id)->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['email' => 'This identity is suspended or already has a membership. Review the existing membership instead.']);
            }
            $member = Membership::create([
                'institution_id' => $institution->id, 'user_id' => $user->id,
                'role_id' => $role->id, 'status' => 'pending', 'identifier' => $data['identifier'] ?? null,
                'created_by' => auth()->id(),
            ]);
            Audit::record('membership.invited', $member, $institution->id, ['role_id' => $role->id, 'existing_identity' => $user->status === 'active']);

            return $member;
        });
        $this->invite($membership);

        return $membership;
    }

    public function authorizeRole(Institution $institution, Role $role): void
    {
        abort_unless($role->scope === 'institution', 403);
        if (InstitutionAccess::system()) {
            return;
        }
        abort_if($role->name === 'Institution Administrator', 403);
        $actor = InstitutionAccess::membership($institution);
        abort_unless($actor && empty(array_diff($role->permissions, InstitutionAccess::effective($actor))), 403);
    }

    public function invite(Membership $membership, bool $send = true): string
    {
        $membership->load('user', 'institution');
        abort_if(in_array($membership->institution->status, ['suspended', 'archived'], true) || $membership->user->status === 'suspended', 403);
        $token = bin2hex(random_bytes(32));
        $invitation = DB::transaction(function () use ($membership, $token): IdentityInvitation {
            Membership::whereKey($membership->id)->lockForUpdate()->firstOrFail();
            IdentityInvitation::where('membership_id', $membership->id)->whereNull('accepted_at')->update(['expires_at' => now()]);

            return IdentityInvitation::create([
                'uuid' => Str::uuid()->toString(), 'membership_id' => $membership->id,
                'purpose' => $membership->user->status === 'pending' ? 'activate' : 'membership',
                'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours(48),
            ]);
        });
        $url = URL::temporarySignedRoute('activation.show', $invitation->expires_at, ['invitation' => $invitation->uuid, 'token' => $token]);
        if ($send) {
            try {
                $driver = config('mail.mailers.'.config('mail.default').'.transport');
                if ($driver === 'log') {
                    $status = 'assistance_required';
                } else {
                    Mail::to($membership->user->email)->send(new IdentityInvitationMail($membership, $url));
                    $status = $driver === 'array' ? 'simulated' : 'unconfirmed';
                }
            } catch (Throwable) {
                $status = 'failed';
            }
            $invitation->update(['mail_status' => $status]);
        }
        Audit::record('identity.invitation_issued', $invitation, $membership->institution_id, ['assisted' => ! $send]);

        return $url;
    }

    public function resolve(IdentityInvitation $invitation, string $token): Membership
    {
        abort_unless(! $invitation->accepted_at && $invitation->expires_at->isFuture() && hash_equals($invitation->token_hash, hash('sha256', $token)), 403);
        $member = Membership::with('user', 'institution')->findOrFail($invitation->membership_id);
        abort_unless($member->status === 'pending' && ! in_array($member->institution->status, ['suspended', 'archived'], true) && $member->user->status !== 'suspended', 403);

        return $member;
    }

    public function accept(IdentityInvitation $invitation, string $token, ?string $password): User
    {
        return DB::transaction(function () use ($invitation, $token, $password): User {
            $locked = IdentityInvitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            $member = $this->resolve($locked, $token);
            $user = User::whereKey($member->user_id)->lockForUpdate()->firstOrFail();
            if ($user->status === 'active') {
                abort_unless(auth()->id() === $user->id, 403);
                if (! $user->email_verified_at) {
                    $user->forceFill(['email_verified_at' => now()])->save();
                }
            } else {
                abort_unless($password, 422);
                $user->forceFill(['password' => $password, 'status' => 'active', 'email_verified_at' => now()])->save();
            }
            $member->update(['status' => 'active']);
            $locked->update(['accepted_at' => now()]);
            Audit::record('membership.accepted', $member, $member->institution_id, [], $user->id);

            return $user;
        });
    }

    public function protectFinalAdministrator(Institution $institution, Membership $member, int $roleId, string $status): void
    {
        $isAdmin = $member->role->name === 'Institution Administrator' && $member->status === 'active' && $member->user->status === 'active';
        if ($isAdmin && ($status !== 'active' || Role::findOrFail($roleId)->name !== 'Institution Administrator')) {
            $count = Membership::where('institution_id', $institution->id)->where('status', 'active')
                ->whereHas('role', fn ($query) => $query->where('name', 'Institution Administrator'))
                ->whereHas('user', fn ($query) => $query->where('status', 'active'))->count();
            if ($count <= 1) {
                throw ValidationException::withMessages(['status' => 'The final active Institution Administrator cannot be removed or suspended.']);
            }
        }
    }
}

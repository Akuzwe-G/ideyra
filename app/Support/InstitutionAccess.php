<?php

namespace App\Support;

use App\Models\Institution;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class InstitutionAccess
{
    public static function system(?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user && $user->status === 'active' && $user->roles()->where('name', 'System Administrator')->where('scope', 'platform')->exists();
    }

    public static function requireSystem(): void
    {
        abort_unless(self::system(), 403);
    }

    public static function membership(Institution $institution, ?User $user = null): ?Membership
    {
        $user ??= auth()->user();
        if (! $user || $user->status !== 'active' || $institution->status !== 'active') {
            return null;
        }

        return Membership::with('role')->where('institution_id', $institution->id)->where('user_id', $user->id)->where('status', 'active')->first();
    }

    public static function effective(Membership $membership): array
    {
        $permissions = array_fill_keys($membership->role->permissions, true);
        foreach (DB::table('membership_permissions')->join('permissions', 'permissions.id', '=', 'membership_permissions.permission_id')
            ->where('membership_id', $membership->id)->where('permissions.scope', 'institution')->get(['permissions.name', 'membership_permissions.allowed']) as $override) {
            $permissions[$override->name] = (bool) $override->allowed;
        }

        return array_keys(array_filter($permissions));
    }

    public static function allows(Institution $institution, string $permission): bool
    {
        if (self::system()) {
            return true;
        }
        $membership = self::membership($institution);

        return $membership && in_array($permission, self::effective($membership), true);
    }

    public static function authorize(Institution $institution, ?string $permission = null): void
    {
        abort_unless(self::system() || ($permission ? self::allows($institution, $permission) : self::membership($institution) !== null), 403);
    }

    public static function scopedMember(Institution $institution, Membership $membership): void
    {
        abort_unless($membership->institution_id === $institution->id, 404);
    }
}

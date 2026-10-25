<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PhaseSixSeeder extends Seeder
{
    public function run(): void
    {
        $keys = array_keys(array_filter(config('permissions'), fn ($v) => $v['group'] === 'Intelligence & reports'));
        foreach ($keys as $key) {
            Permission::firstOrCreate(['name' => $key], config('permissions')[$key]);
        }
        if (DB::table('platform_settings')->where('key', 'phase06_permissions_initialized')->exists()) {
            return;
        }
        DB::transaction(function () use ($keys): void {
            foreach (['System Administrator', 'Institution Administrator', 'Lecturer / Teacher', 'Mentor / Supervisor'] as $name) {
                $role = Role::where('name', $name)->lockForUpdate()->first();
                if (! $role) {
                    continue;
                }
                $grants = match ($name) {
                    'System Administrator' => array_values(array_filter($keys, fn ($key) => config('permissions')[$key]['scope'] === 'platform')),
                    'Institution Administrator' => array_values(array_filter($keys, fn ($key) => ! str_ends_with($key, '_platform'))),
                    default => ['analytics.view_programme', 'analytics.export', 'reports.generate', 'reports.view_history'],
                };
                $role->update(['permissions' => array_values(array_unique(array_merge($role->permissions, $grants)))]);
            }
            DB::table('platform_settings')->insertOrIgnore(['key' => 'phase06_permissions_initialized', 'value' => 'true']);
        });
    }
}

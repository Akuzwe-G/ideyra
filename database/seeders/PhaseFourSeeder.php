<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PhaseFourSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['career.verify', 'career.report'] as $key) {
            Permission::firstOrCreate(['name' => $key], config('permissions')[$key]);
        }
        if (DB::table('platform_settings')->where('key', 'phase04_permissions_initialized')->exists()) {
            return;
        }
        DB::transaction(function (): void {
            $role = Role::where('name', 'Institution Administrator')->lockForUpdate()->first();
            if ($role) {
                $role->update(['permissions' => array_values(array_unique(array_merge($role->permissions, ['career.verify', 'career.report'])))]);
            }
            DB::table('platform_settings')->insertOrIgnore(['key' => 'phase04_permissions_initialized', 'value' => 'true']);
        });
    }
}

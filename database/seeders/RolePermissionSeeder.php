<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('permissions') as $name => $definition) {
            Permission::firstOrCreate(['name' => $name], $definition);
        }
        $institution = Permission::where('scope', 'institution')->pluck('name')->all();
        $platform = Permission::where('scope', 'platform')->pluck('name')->all();
        foreach ([
            'System Administrator' => ['platform', $platform],
            'Institution Administrator' => ['institution', $institution],
            'Lecturer / Teacher' => ['institution', ['academic.view_assigned_classes', 'projects.view_assigned', 'projects.create', 'projects.edit_own', 'opportunities.view']],
            'Mentor / Supervisor' => ['institution', ['academic.view_assigned_classes', 'projects.view_assigned', 'opportunities.view']],
            'Student' => ['institution', ['academic.view_assigned_classes', 'projects.create', 'projects.edit_own', 'opportunities.view']],
        ] as $name => [$scope, $permissions]) {
            Role::firstOrCreate(['name' => $name], compact('scope', 'permissions'));
        }
        DB::table('platform_settings')->insertOrIgnore(['key' => 'applications_open', 'value' => json_encode(true)]);
    }
}

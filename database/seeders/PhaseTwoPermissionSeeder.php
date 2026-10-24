<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PhaseTwoPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $implemented = ['projects.view_assigned', 'projects.create', 'projects.create_institutional', 'projects.edit_own', 'projects.manage_assigned', 'projects.review', 'projects.approve', 'academic.supervise_projects', 'academic.review_work'];
        foreach (config('permissions') as $key => $definition) {
            Permission::firstOrCreate(['name' => $key], $definition);
        }
        Permission::whereIn('name', $implemented)->update(['implemented' => true]);
        if (DB::table('platform_settings')->where('key', 'phase02_permissions_initialized')->exists()) {
            return;
        }
        $common = ['ideas.create', 'ideas.view_own', 'ideas.edit_own', 'ideas.share', 'ideas.view_shared', 'ideas.submit_for_review', 'projects.view_assigned', 'groups.view_assigned', 'tasks.update_assigned', 'deliverables.submit'];
        $additions = [
            'Institution Administrator' => Permission::where('scope', 'institution')->pluck('name')->all(),
            'Student' => array_merge($common, ['tasks.create', 'tasks.assign', 'deliverables.create']),
            'Lecturer / Teacher' => array_merge($common, ['ideas.review', 'groups.create', 'groups.manage_assigned', 'groups.assign_members', 'groups.assign_leaders', 'groups.assign_supervisors', 'projects.supervise', 'deliverables.review', 'deliverables.approve', 'tasks.create', 'tasks.assign', 'deliverables.create']),
            'Mentor / Supervisor' => array_merge($common, ['ideas.review', 'projects.supervise', 'deliverables.review', 'deliverables.approve']),
        ];
        DB::transaction(function () use ($additions): void {
            foreach ($additions as $name => $keys) {
                $role = Role::where('name', $name)->lockForUpdate()->first();
                if ($role) {
                    $role->update(['permissions' => array_values(array_unique(array_merge($role->permissions, $keys)))]);
                }
            }
            DB::table('platform_settings')->insertOrIgnore(['key' => 'phase02_permissions_initialized', 'value' => 'true']);
        });
    }
}

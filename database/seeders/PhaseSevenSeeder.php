<?php

namespace Database\Seeders;

use App\Models\OpportunityCategory;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PhaseSevenSeeder extends Seeder
{
    public function run(): void
    {
        $keys = [];
        foreach (config('permissions') as $name => $definition) {
            if ($definition['group'] === 'Innovation network') {
                Permission::firstOrCreate(['name' => $name], $definition);
                $keys[] = $name;
            }
        }
        foreach (['Innovation challenges' => 'innovation-challenges', 'Events and exhibitions' => 'events-exhibitions'] as $name => $slug) {
            OpportunityCategory::firstOrCreate(['slug' => $slug], ['name' => $name, 'active' => true]);
        }
        if (DB::table('platform_settings')->where('key', 'phase07_permissions_initialized')->exists()) {
            return;
        }
        DB::transaction(function () use ($keys): void {
            foreach (['System Administrator', 'Institution Administrator', 'Lecturer / Teacher', 'Mentor / Supervisor', 'Student'] as $name) {
                $role = Role::where('name', $name)->lockForUpdate()->first();
                if (! $role) {
                    continue;
                }
                $grants = match ($name) {
                    'System Administrator' => ['moderation.review_platform', 'innovation-analytics.view_platform'],'Institution Administrator' => array_values(array_filter($keys, fn ($k) => config('permissions')[$k]['scope'] === 'institution')),'Student' => ['showcase.submit', 'collaborations.request'],default => ['showcase.submit', 'showcase.review', 'collaborations.request', 'challenges.judge']
                };
                $role->update(['permissions' => array_values(array_unique([...$role->permissions, ...$grants]))]);
            }
            DB::table('platform_settings')->insertOrIgnore(['key' => 'phase07_permissions_initialized', 'value' => 'true']);
        });
    }
}

<?php

namespace Database\Seeders;

use App\Models\OpportunityCategory;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PhaseThreeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('permissions') as $key => $definition) {
            Permission::firstOrCreate(['name' => $key], $definition);
        }
        Permission::where('name', 'like', 'opportunities.%')->orWhereIn('name', ['projects.sponsor', 'projects.manage_sponsorship', 'projects.review_supported'])->update(['implemented' => true]);
        foreach (['Hackathons', 'Competitions and innovation challenges', 'Internships', 'Scholarships', 'Fellowships', 'Grants and funding calls', 'Entrepreneurship and incubation', 'Research opportunities', 'Workshops and training', 'Other approved opportunities'] as $name) {
            OpportunityCategory::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'active' => true]);
        }
        if (DB::table('platform_settings')->where('key', 'phase03_permissions_initialized')->exists()) {
            return;
        }
        DB::transaction(function (): void {
            foreach (['Institution Administrator' => array_keys(array_filter(config('permissions'), fn ($v, $k) => str_starts_with($k, 'opportunities.') || str_starts_with($k, 'sponsorships.'), ARRAY_FILTER_USE_BOTH)), 'Student' => ['opportunities.view', 'opportunities.apply', 'sponsorships.request'], 'Lecturer / Teacher' => ['opportunities.view'], 'Mentor / Supervisor' => ['opportunities.view']] as $name => $keys) {
                $role = Role::where('name', $name)->lockForUpdate()->first();
                if ($role) {
                    $role->update(['permissions' => array_values(array_unique(array_merge($role->permissions, $keys)))]);
                }
            }
            DB::table('platform_settings')->insertOrIgnore(['key' => 'phase03_permissions_initialized', 'value' => 'true']);
        });
    }
}

<?php

namespace Database\Factories;

use App\Models\GroupMember;
use App\Models\Membership;
use App\Models\ProjectGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GroupMember> */
class GroupMemberFactory extends Factory
{
    public function definition(): array
    {
        return ['group_id' => ProjectGroup::factory(), 'institution_id' => fn (array $a) => ProjectGroup::findOrFail($a['group_id'])->institution_id, 'membership_id' => fn (array $a) => Membership::factory()->create(['institution_id' => $a['institution_id']])->id, 'role' => 'member', 'delegated_permissions' => []];
    }
}

<?php

namespace Database\Factories;

use App\Models\Institution;
use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Membership> */
class MembershipFactory extends Factory
{
    public function definition(): array
    {
        return ['institution_id' => Institution::factory(), 'user_id' => User::factory(), 'role_id' => Role::where('name', 'Student')->firstOrFail()->id, 'status' => 'active', 'created_by' => User::factory()];
    }
}

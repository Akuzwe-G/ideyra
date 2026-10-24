<?php

namespace Database\Factories;

use App\Models\AcademicClass;
use App\Models\Institution;
use App\Models\ProjectGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProjectGroup> */
class ProjectGroupFactory extends Factory
{
    public function definition(): array
    {
        return ['identifier' => (string) Str::ulid(), 'institution_id' => Institution::factory(), 'created_by' => User::factory(), 'academic_class_id' => fn (array $a) => AcademicClass::factory()->create(['institution_id' => $a['institution_id']])->id, 'name' => fake()->sentence(3), 'status' => 'draft'];
    }
}

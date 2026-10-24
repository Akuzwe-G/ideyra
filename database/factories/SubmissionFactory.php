<?php

namespace Database\Factories;

use App\Models\Deliverable;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Submission> */
class SubmissionFactory extends Factory
{
    public function definition(): array
    {
        return ['deliverable_id' => Deliverable::factory(), 'project_id' => fn (array $a) => Deliverable::findOrFail($a['deliverable_id'])->project_id, 'submitted_by' => fn (array $a) => Deliverable::findOrFail($a['deliverable_id'])->assigned_user_id, 'version' => 1, 'body' => fake()->paragraph()];
    }
}

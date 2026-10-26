<?php

namespace Database\Factories;

use App\Models\ContentReport;
use App\Models\Institution;
use App\Models\NetworkPublication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContentReport> */
class ContentReportFactory extends Factory
{
    public function definition(): array
    {
        return ['institution_id' => Institution::factory(), 'user_id' => User::factory(), 'target_type' => 'publication', 'target_id' => NetworkPublication::factory(), 'reason' => 'Private moderation report', 'status' => 'open'];
    }
}

<?php

namespace Database\Factories;

use App\Models\Institution;
use App\Models\ReportSchedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReportSchedule> */
class ReportScheduleFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'institution_id' => Institution::factory(), 'report_type' => 'overview', 'format' => 'csv', 'frequency' => 'monthly', 'filters' => [], 'recipient_ids' => [], 'next_run_at' => now()->addMonth()->startOfMonth()->setTime(4, 0), 'enabled' => true];
    }
}

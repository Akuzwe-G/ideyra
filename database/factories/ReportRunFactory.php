<?php

namespace Database\Factories;

use App\Models\Institution;
use App\Models\ReportRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ReportRun> */
class ReportRunFactory extends Factory
{
    public function definition(): array
    {
        return ['identifier' => (string) Str::ulid(), 'user_id' => User::factory(), 'institution_id' => Institution::factory(), 'report_type' => 'overview', 'format' => 'csv', 'status' => 'pending', 'filters' => ['from' => today()->startOfMonth()->toDateString(), 'to' => today()->toDateString()], 'access_scope' => ['class_ids' => null], 'recipient_ids' => [], 'definition_version' => config('intelligence.version'), 'expires_at' => now()->addDays(180)];
    }
}

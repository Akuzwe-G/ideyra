<?php

namespace Database\Factories;

use App\Models\AchievementVerification;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AchievementVerification> */
class AchievementVerificationFactory extends Factory
{
    public function definition(): array
    {
        return ['reference' => (string) Str::uuid(), 'user_id' => User::factory(), 'institution_id' => Institution::factory(), 'category' => 'achievement', 'subject_key' => 'fixture:'.Str::uuid(), 'title' => 'Academic award', 'status' => 'requested', 'evidence' => ['statement' => 'Private evidence statement', 'subject' => []]];
    }
}

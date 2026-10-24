<?php

namespace Database\Factories;

use App\Models\AiUsage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiUsage>
 */
class AiUsageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'feature' => 'career',
            'provider' => 'test-fixture',
            'model' => 'test-fixture',
            'status' => 'success',
            'input_tokens' => 100,
            'output_tokens' => 50,
        ];
    }
}

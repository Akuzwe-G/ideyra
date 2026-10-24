<?php

namespace Database\Factories;

use App\Models\Portfolio;
use App\Models\PortfolioMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PortfolioMessage> */
class PortfolioMessageFactory extends Factory
{
    public function definition(): array
    {
        return ['portfolio_id' => Portfolio::factory(), 'submission_key' => hash('sha256', fake()->uuid()), 'sender_name' => fake()->name(), 'sender_email' => fake()->safeEmail(), 'message' => 'A professional enquiry fixture.'];
    }
}

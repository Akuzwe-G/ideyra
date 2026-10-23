<?php

namespace Database\Factories;

use App\Models\Enquiry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Enquiry> */
class EnquiryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(), 'email' => fake()->safeEmail(),
            'category' => 'feedback', 'subject' => fake()->sentence(),
            'message' => fake()->paragraph(), 'consented_at' => now(),
        ];
    }
}

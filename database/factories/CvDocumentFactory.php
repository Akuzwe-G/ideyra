<?php

namespace Database\Factories;

use App\Models\CvDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CvDocument> */
class CvDocumentFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'title' => 'Private internship CV', 'template' => 'classic', 'sections' => ['summary', 'skill'], 'content' => ['contact' => 'Professional name', 'summary' => 'An accurate summary.', 'skill' => 'Research']];
    }
}

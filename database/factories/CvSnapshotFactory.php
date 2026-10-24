<?php

namespace Database\Factories;

use App\Models\CvDocument;
use App\Models\CvSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CvSnapshot> */
class CvSnapshotFactory extends Factory
{
    public function definition(): array
    {
        return ['cv_document_id' => CvDocument::factory(), 'version' => 1, 'payload' => ['title' => 'Historical CV', 'template' => 'classic', 'sections' => ['summary'], 'content' => ['summary' => 'Original content']]];
    }
}

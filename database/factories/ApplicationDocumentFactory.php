<?php

namespace Database\Factories;

use App\Models\ApplicationDocument;
use App\Models\OpportunityApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ApplicationDocument> */
class ApplicationDocumentFactory extends Factory
{
    public function definition(): array
    {
        return ['application_id' => OpportunityApplication::factory(), 'uploaded_by' => fn (array $a) => OpportunityApplication::findOrFail($a['application_id'])->applicant_id, 'path' => 'isolated-test-document.pdf', 'name' => 'evidence.pdf', 'mime' => 'application/pdf', 'bytes' => 100, 'version' => 0];
    }
}

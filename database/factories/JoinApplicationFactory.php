<?php

namespace Database\Factories;

use App\Models\JoinApplication;
use App\Support\ApplicationSchema;
use App\Support\ApplicationWorkflow;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<JoinApplication> */
class JoinApplicationFactory extends Factory
{
    public function definition(): array
    {
        $payload = [];
        foreach (ApplicationSchema::fields('institution') as $key => $field) {
            $payload[$key] = match ($field[1]) {
                'select' => $field[3][0], 'checkboxes' => [$field[3][0]], 'number' => 100,
                'email' => fake()->safeEmail(), 'url' => 'https://example.test', default => 'A meaningful response for '.$key,
            };
        }
        $payload['consent_recorded_at'] = now()->toIso8601String();

        return ['uuid' => (string) Str::ulid(), 'reference' => 'IDEYRA-TEST-'.fake()->unique()->bothify('########??'), 'type' => 'institution', 'country' => 'Rwanda', 'payload' => $payload, 'search_tokens' => ApplicationWorkflow::searchTokens($payload['applicant_name'].' '.$payload['institution_name']), 'institution_tokens' => ApplicationWorkflow::searchTokens($payload['institution_name']), 'submission_hash' => hash('sha256', random_bytes(32)), 'duplicate_hash' => hash('sha256', random_bytes(32)), 'status' => 'submitted'];
    }
}

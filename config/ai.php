<?php

return [
    'enabled' => env('IDEYRA_AI_ENABLED', false),
    'provider' => env('IDEYRA_AI_PROVIDER', 'openai-compatible'),
    'model' => env('IDEYRA_AI_MODEL', ''),
    'endpoint' => env('IDEYRA_AI_ENDPOINT', 'https://api.openai.com/v1/chat/completions'),
    'key' => env('IDEYRA_AI_API_KEY', ''),
    'timeout' => (int) env('IDEYRA_AI_TIMEOUT', 30),
    'max_tokens' => (int) env('IDEYRA_AI_MAX_TOKENS', 1200),
    'max_input' => 12000,
    'max_output' => 16000,
    'user_daily' => (int) env('IDEYRA_AI_USER_DAILY', 20),
    'institution_daily' => (int) env('IDEYRA_AI_INSTITUTION_DAILY', 500),
    'platform_daily' => (int) env('IDEYRA_AI_PLATFORM_DAILY', 2000),
    'retention_days' => 90,
    'input_cost_per_million' => null,
    'output_cost_per_million' => null,
    'features' => [
        'idea' => 'Idea Coach',
        'project' => 'Project Mentor',
        'career' => 'Career Explorer',
        'cv' => 'CV Assistant',
        'opportunity' => 'Opportunity Explorer',
        'supervision' => 'Supervision Assistant',
    ],
];

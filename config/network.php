<?php

return [
    'collaboration_types' => ['technical' => 'Technical collaboration', 'research' => 'Research collaboration', 'design' => 'Design assistance', 'mentorship' => 'Mentorship', 'business' => 'Business development', 'testing' => 'Testing and validation', 'other' => 'Other support'],
    'visibility' => ['private' => 'Private project team', 'institution' => 'Institutional Showcase', 'network' => 'Ideyra Network Showcase', 'public' => 'Public Showcase'],
    'request_days' => 30,
    'max_image_bytes' => 5242880,
    'max_file_kb' => 5120,
    'permissions' => [
        'showcase.submit', 'showcase.review', 'showcase.publish', 'showcase.unpublish', 'network.policy',
        'collaborations.request', 'collaborations.review', 'collaborations.manage',
        'challenges.create', 'challenges.publish', 'challenges.manage', 'challenges.judge',
        'events.create', 'events.publish', 'events.manage', 'network.achievements_confirm',
        'moderation.review', 'innovation-analytics.view',
    ],
];

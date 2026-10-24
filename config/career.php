<?php

return [
    'sections' => ['summary' => 'Professional summary', 'education' => 'Education', 'skill' => 'Skills', 'experience' => 'Experience', 'project' => 'Projects', 'certification' => 'Certifications', 'achievement' => 'Achievements', 'language' => 'Languages', 'references' => 'References'],
    'entry_types' => ['education' => 'Education', 'skill' => 'Skills', 'experience' => 'Experience', 'certification' => 'Certifications', 'achievement' => 'Achievements', 'language' => 'Languages'],
    'templates' => ['classic' => 'Classic Professional', 'modern' => 'Modern Graduate', 'technical' => 'Technical Portfolio CV'],
    'themes' => ['minimal' => 'Ideyra Minimal', 'creative' => 'Creative Innovator', 'technical' => 'Technical Builder'],
    'categories' => ['Technical', 'Communication', 'Leadership', 'Research', 'Design', 'Business', 'Other'],
    'verification_categories' => ['academic_project' => 'Academic project and publication approval', 'leadership' => 'Project leadership', 'participation' => 'Project participation', 'achievement' => 'Institution-confirmed achievement', 'award' => 'Award', 'certificate' => 'Certificate', 'skill' => 'Skill evidence'],
    'fields' => [
        'education' => ['organisation' => 'Institution', 'qualification' => 'Qualification / programme', 'field' => 'Field of study', 'start' => 'Start date', 'end' => 'End / expected graduation date', 'description' => 'Description'],
        'skill' => ['proficiency' => 'Proficiency (optional)', 'description' => 'Supporting evidence / description', 'link' => 'Evidence link'],
        'experience' => ['organisation' => 'Organisation', 'position' => 'Position / role', 'engagement' => 'Engagement type', 'start' => 'Start date', 'end' => 'End date', 'description' => 'Responsibilities', 'achievements' => 'Key achievements', 'link' => 'Supporting link'],
        'certification' => ['organisation' => 'Issuing organisation', 'start' => 'Issue date', 'end' => 'Expiration date', 'credential' => 'Credential identifier', 'link' => 'Verification URL', 'description' => 'Description'],
        'achievement' => ['organisation' => 'Issuing organisation', 'start' => 'Achievement date', 'description' => 'Description', 'link' => 'Evidence link'],
        'language' => ['proficiency' => 'Proficiency'],
    ],
];

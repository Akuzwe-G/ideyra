<?php

namespace App\Support;

use Illuminate\Validation\Rule;

class ApplicationSchema
{
    public static function sections(string $type): array
    {
        $types = ['university', 'college', 'school', 'technical', 'other'];
        $yesNo = ['yes', 'no'];
        $features = ['ideas', 'projects', 'collaboration', 'opportunities', 'profiles', 'portfolios', 'academic_management'];
        $institution = [
            'institution_name' => ['Institution’s full official name', 'text', 'required'],
            'institution_type' => ['Institution type', 'select', 'required', $types],
            'country' => ['Institution country', 'text', 'required'],
            'region' => ['Region / province / district', 'text', 'required'],
            'city' => ['City or town', 'text', 'required'],
            'campus' => ['Physical address or campus location', 'text', 'required'],
            'website' => ['Official website, if known', 'url', 'nullable'],
            'student_population' => ['Estimated total student population', 'number', $type === 'institution' ? 'required' : 'nullable'],
        ];
        if ($type === 'institution') {
            return [
                'Institution information' => $institution + [
                    'initial_students' => ['Estimated initial students', 'number', 'required'],
                    'campuses' => ['Number of campuses', 'number', 'nullable'],
                ],
                'Applicant information' => [
                    'applicant_name' => ['Applicant’s full name', 'text', 'required'],
                    'position' => ['Official position or role', 'text', 'required'],
                    'email' => ['Institutional email', 'email', 'required'],
                    'alternative_email' => ['Alternative contact email', 'email', 'nullable'],
                    'phone' => ['Phone number', 'tel', 'required'],
                    'authorised' => ['Are you authorised to represent this institution?', 'select', 'required', $yesNo],
                    'decision_maker' => ['Decision-maker name or role (required if you are not authorised)', 'text', 'required_if:authorised,no'],
                ],
                'Current project environment' => [
                    'innovation_programmes' => ['Does the institution run innovation, research, entrepreneurship or project programmes?', 'select', 'required', $yesNo],
                    'project_management' => ['How are ideas and projects currently recorded or managed?', 'textarea', 'required'],
                    'project_challenges' => ['What challenges arise in tracking student projects?', 'textarea', 'required'],
                    'opportunity_support' => ['Are students supported in finding competitions, internships, hackathons or scholarships?', 'select', 'required', ['yes', 'partly', 'no']],
                    'innovation_hub' => ['Is there an innovation hub, incubation centre or supervision structure?', 'select', 'required', $yesNo],
                    'hub_details' => ['Briefly describe that structure (if present)', 'textarea', 'required_if:innovation_hub,yes'],
                ],
                'Needs and readiness' => [
                    'features' => ['Most valuable Ideyra capabilities', 'checkboxes', 'required', $features],
                    'problems_to_solve' => ['What would you most like Ideyra to solve?', 'textarea', 'required'],
                    'first_departments' => ['Which programmes or departments would participate first?', 'text', 'required'],
                    'staff_involved' => ['Estimated lecturers, mentors or administrators involved', 'number', 'required'],
                    'target_start' => ['When would you ideally begin?', 'select', 'required', ['when_ready', 'next_term', 'next_year', 'exploring']],
                    'pilot_interest' => ['Interested in a pilot programme?', 'select', 'required', ['yes', 'maybe', 'no']],
                    'comments' => ['Additional information', 'textarea', 'nullable'],
                ],
            ];
        }

        return [
            'Student information' => [
                'applicant_name' => ['Full name', 'text', 'required'],
                'email' => ['Email address', 'email', 'required'],
                'phone' => ['Phone number', 'tel', 'nullable'],
                'student_country' => ['Your country', 'text', 'required'],
                'student_city' => ['Your city or town', 'text', 'required'],
                'programme' => ['Programme or field of study', 'text', 'required'],
                'academic_level' => ['Current academic year or level', 'text', 'required'],
                'graduation_year' => ['Expected graduation year', 'year', 'nullable'],
            ],
            'Institution information' => $institution + [
                'representative_contact' => ['Relevant lecturer, mentor or administrator contact, if known', 'text', 'nullable'],
            ],
            'Motivation and need' => [
                'project_challenges' => ['What challenges do students face managing ideas and academic projects?', 'textarea', 'required'],
                'project_management' => ['How are projects currently organised or supervised?', 'textarea', 'required'],
                'features' => ['Which Ideyra features would be most useful?', 'checkboxes', 'required', $features],
                'missing_opportunities' => ['Do students miss opportunities because information is hard to access?', 'select', 'required', ['yes', 'sometimes', 'no']],
                'introduce_interest' => ['Would you help introduce Ideyra at your institution?', 'select', 'required', ['yes', 'maybe', 'no']],
                'discussed' => ['Have you discussed this with an institutional representative?', 'select', 'required', $yesNo],
                'discussion_details' => ['Briefly describe that discussion (if applicable)', 'textarea', 'required_if:discussed,yes'],
                'comments' => ['Additional comments', 'textarea', 'nullable'],
            ],
        ];
    }

    public static function fields(string $type): array
    {
        return array_merge(...array_values(self::sections($type)));
    }

    public static function rules(string $type): array
    {
        $rules = [];
        foreach (self::fields($type) as $key => [$label, $kind, $required]) {
            $rules[$key] = [$required];
            if ($kind === 'checkboxes') {
                $rules[$key] = [$required, 'array', 'min:1', 'max:7'];
                $rules[$key.'.*'] = ['string', 'distinct', Rule::in(self::fields($type)[$key][3])];
            } elseif ($kind === 'select') {
                $rules[$key][] = Rule::in(self::fields($type)[$key][3]);
            } elseif (in_array($kind, ['number', 'year'], true)) {
                $rules[$key] = [$required, 'integer', $kind === 'year' ? 'between:1900,2200' : 'between:0,10000000'];
            } else {
                $rules[$key][] = 'string';
                $rules[$key][] = $kind === 'textarea' ? 'max:2000' : 'max:255';
                if ($kind === 'email') {
                    $rules[$key][] = 'email';
                }
                if ($kind === 'url') {
                    $rules[$key][] = 'url:http,https';
                }
            }
        }
        if ($type === 'institution') {
            $rules['initial_students'][] = 'lte:student_population';
        }

        return $rules + ['consent' => ['accepted'], 'review_confirmed' => ['accepted'], 'website_trap' => ['nullable', 'string', 'max:0'], 'submission_key' => ['required', 'string', 'size:64']];
    }
}

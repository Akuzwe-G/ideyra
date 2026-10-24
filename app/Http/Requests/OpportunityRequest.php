<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OpportunityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->status === 'active';
    }

    protected function prepareForValidation(): void
    {
        $questions = $this->input('questions', []);
        if (is_array($questions)) {
            $this->merge(['questions' => array_values(array_filter($questions, fn ($question) => is_array($question) && ! empty($question['label'])))]);
        }
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:180', 'category_id' => 'required|integer|exists:opportunity_categories,id', 'institution_id' => 'nullable|integer|exists:institutions,id', 'summary' => 'required|string|max:600', 'description' => 'required|string|max:40000', 'organiser' => 'required|string|max:180', 'source_attribution' => 'required|string|max:250', 'source_url' => 'nullable|url:http,https|max:2048', 'application_method' => 'required|in:internal,external', 'external_url' => 'required_if:application_method,external|nullable|url:https|max:2048', 'instructions' => 'required|string|max:12000', 'eligibility_text' => 'required|string|max:6000', 'country' => 'nullable|string|max:100', 'delivery_mode' => 'required|in:online,in_person,hybrid', 'venue' => 'nullable|string|max:250',
            'opens_at' => 'nullable|date', 'deadline_at' => ['nullable', 'date', Rule::when($this->filled('opens_at'), 'after:opens_at')], 'event_starts_at' => 'nullable|date_format:Y-m-d', 'event_ends_at' => ['nullable', 'date_format:Y-m-d', Rule::when($this->filled('event_starts_at'), 'after_or_equal:event_starts_at')], 'places' => 'nullable|integer|min:1|max:1000000', 'award_information' => 'nullable|string|max:6000', 'cover' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:4096|dimensions:max_width=4096,max_height=4096', 'visibility' => 'required|in:platform,institution,restricted', 'allow_groups' => 'nullable|boolean', 'notify_publication' => 'nullable|boolean',
            'audience' => 'nullable|array:role,class,programme,academic_year,intake,group', 'audience.*' => 'array|max:100', 'audience.*.*' => 'integer|distinct',
            'eligibility' => 'nullable|array:role,class,programme,academic_year,intake,group', 'eligibility.*' => 'array|max:100', 'eligibility.*.*' => 'integer|distinct',
            'questions' => 'nullable|array|max:12', 'questions.*.label' => 'required|string|max:180', 'questions.*.type' => 'required|in:text,textarea,number,boolean,select,document', 'questions.*.required' => 'nullable|boolean', 'questions.*.options_text' => 'nullable|string|max:1000',
        ];
    }
}

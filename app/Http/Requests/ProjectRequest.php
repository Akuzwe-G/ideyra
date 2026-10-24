<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->status === 'active';
    }

    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:180'], 'description' => ['required', 'string', 'max:12000'], 'problem' => ['required', 'string', 'max:6000'], 'objectives' => ['required', 'string', 'max:6000'], 'outcomes' => ['required', 'string', 'max:6000'], 'category' => ['required', 'string', 'max:100'], 'institution_id' => ['nullable', 'integer', 'exists:institutions,id'], 'academic_class_id' => ['nullable', 'integer', 'exists:academic_classes,id'], 'group_id' => ['nullable', 'integer', 'exists:project_groups,id'], 'idea_id' => ['nullable', 'integer', 'exists:ideas,id'], 'starts_at' => ['nullable', 'date_format:Y-m-d'], 'target_at' => ['nullable', 'date_format:Y-m-d', Rule::when($this->filled('starts_at'), 'after_or_equal:starts_at')], 'confirm_share' => ['sometimes', 'nullable', 'accepted'], 'confirm_owner' => [$this->isMethod('POST') ? 'accepted' : 'nullable']];
    }
}

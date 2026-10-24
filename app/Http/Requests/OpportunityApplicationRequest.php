<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpportunityApplicationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('group_id') && $this->has('group_participants')) {
            $this->merge(['participant_ids' => $this->input('group_participants.'.$this->input('group_id'), [])]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->status === 'active';
    }

    public function rules(): array
    {
        return ['career' => 'nullable|array:cv_id,profile_fields,achievement_ids,showcase_ids', 'career.cv_id' => 'nullable|integer', 'career.profile_fields' => 'nullable|array|max:7', 'career.profile_fields.*' => 'required|distinct|in:display_name,headline,biography,location,professional_email,phone,links', 'career.achievement_ids' => 'nullable|array|max:30', 'career.achievement_ids.*' => 'integer|distinct', 'career.showcase_ids' => 'nullable|array|max:10', 'career.showcase_ids.*' => 'integer|distinct', 'institution_id' => 'required|integer|exists:institutions,id', 'group_id' => 'nullable|integer|exists:project_groups,id', 'project_id' => 'nullable|integer|exists:projects,id', 'motivation' => 'required|string|max:12000', 'skills' => 'nullable|string|max:6000', 'project_summary' => 'nullable|string|max:6000', 'participant_ids' => 'nullable|array|max:100', 'participant_ids.*' => 'integer|distinct', 'answers' => 'nullable|array|max:12', 'answers.*' => 'nullable|string|max:12000'];
    }
}

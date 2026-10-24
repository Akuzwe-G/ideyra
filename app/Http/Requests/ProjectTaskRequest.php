<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->status === 'active';
    }

    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:6000'], 'milestone_id' => ['nullable', 'integer', 'exists:milestones,id'], 'priority' => ['required', Rule::in(['low', 'medium', 'high', 'urgent'])], 'status' => ['required', Rule::in(['to_do', 'in_progress', 'in_review', 'completed', 'blocked'])], 'starts_at' => ['nullable', 'date_format:Y-m-d'], 'due_at' => ['required', 'date_format:Y-m-d', Rule::when($this->filled('starts_at'), 'after_or_equal:starts_at')], 'assignee_ids' => ['nullable', 'array', 'max:100'], 'assignee_ids.*' => ['integer', 'distinct']];
    }
}

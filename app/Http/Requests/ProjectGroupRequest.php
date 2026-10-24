<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->status === 'active';
    }

    public function rules(): array
    {
        return ['institution_id' => ['required', 'integer', 'exists:institutions,id'], 'academic_class_id' => ['required', 'integer', 'exists:academic_classes,id'], 'name' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:6000'], 'status' => ['required', Rule::in(['draft', 'active', 'completed', 'archived'])], 'member_ids' => ['nullable', 'array', 'max:100'], 'member_ids.*' => ['integer', 'distinct'], 'leader_id' => ['nullable', 'integer'], 'supervisor_ids' => ['nullable', 'array', 'max:10'], 'supervisor_ids.*' => ['integer', 'distinct'], 'delegated_permissions' => ['nullable', 'array', 'max:5'], 'delegated_permissions.*' => [Rule::in(['groups.manage_assigned', 'groups.assign_members', 'projects.manage_assigned', 'tasks.create', 'tasks.assign'])]];
    }
}

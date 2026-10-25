<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IntelligenceFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->status === 'active';
    }

    public function rules(): array
    {
        return self::filterRules();
    }

    public static function filterRules(): array
    {
        return [
            'institution_id' => 'nullable|integer|min:1', 'scope' => 'nullable|in:platform,institution',
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'group' => 'nullable|in:daily,weekly,monthly', 'compare' => 'nullable|boolean', 'cohort_days' => 'nullable|integer|min:0|max:365',
            'academic_year_id' => 'nullable|integer|min:1', 'intake_id' => 'nullable|integer|min:1', 'programme_id' => 'nullable|integer|min:1', 'academic_class_id' => 'nullable|integer|min:1', 'project_group_id' => 'nullable|integer|min:1', 'supervisor_id' => 'nullable|integer|min:1',
            'project_category' => 'nullable|string|max:100', 'opportunity_category_id' => 'nullable|integer|min:1',
            'status' => ['nullable', Rule::in(['draft', 'pending_approval', 'approved', 'in_progress', 'on_hold', 'completed', 'cancelled', 'archived'])],
            'section' => ['nullable', Rule::in(['overview', 'projects', 'participation', 'programmes', 'opportunities', 'career', 'achievements', 'ai', 'quality'])],
        ];
    }
}

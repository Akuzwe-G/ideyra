<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KpiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->status === 'active';
    }

    public function rules(): array
    {
        return ['title' => 'required|string|max:180', 'description' => 'nullable|string|max:4000', 'category' => 'required|string|max:80',
            'source' => 'required|in:automatic,manual', 'metric' => ['required', Rule::in(['manual', ...array_keys(config('intelligence.metrics'))])],
            'period_from' => 'required|date_format:Y-m-d', 'period_to' => 'required|date_format:Y-m-d|after_or_equal:period_from',
            'target' => 'required|numeric|min:0|max:999999999999', 'target_upper' => 'nullable|numeric|gte:target|max:999999999999', 'direction' => 'required|in:increase,decrease,range',
            'owner_id' => 'required|integer', 'unit' => 'required|string|max:60', 'notes' => 'nullable|string|max:6000', 'reason' => 'required|string|max:2000', 'confirm' => 'accepted',
            'actual' => 'prohibited', 'created_by' => 'prohibited', 'approved_by' => 'prohibited', 'lifecycle' => 'prohibited', 'version' => 'required|integer|min:0',
            'programme_id' => 'nullable|integer', 'academic_year_id' => 'nullable|integer', 'intake_id' => 'nullable|integer', 'academic_class_id' => 'nullable|integer',
        ];
    }
}

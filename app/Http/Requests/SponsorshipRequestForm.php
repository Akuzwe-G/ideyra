<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SponsorshipRequestForm extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->status === 'active';
    }

    public function rules(): array
    {
        return ['project_id' => 'required|integer|exists:projects,id', 'support_type' => 'required|in:financial_pledge,equipment,mentorship,technical_assistance,workspace,training,other', 'description' => 'required|string|max:12000', 'justification' => 'required|string|max:6000', 'intended_use' => 'required|string|max:6000', 'estimated_value' => 'nullable|numeric|min:0|max:999999999999.99', 'currency' => 'required_with:estimated_value|nullable|string|size:3|regex:/^[A-Z]{3}$/', 'needed_from' => 'nullable|date_format:Y-m-d', 'needed_until' => ['nullable', 'date_format:Y-m-d', Rule::when($this->filled('needed_from'), 'after_or_equal:needed_from')]];
    }
}

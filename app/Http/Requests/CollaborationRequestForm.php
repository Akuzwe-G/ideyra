<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CollaborationRequestForm extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->status === 'active';
    }

    public function rules(): array
    {
        return ['institution_id' => 'required|integer|exists:institutions,id', 'type' => 'required|in:technical,research,design,mentorship,business,testing,other', 'motivation' => 'required|string|max:4000', 'skills' => 'required|string|max:3000', 'contribution' => 'required|string|max:4000', 'supporting_project_id' => 'nullable|integer|exists:projects,id', 'confirm' => 'accepted'];
    }
}

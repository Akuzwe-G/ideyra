<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IdeaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->status === 'active';
    }

    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:180'], 'description' => ['required', 'string', 'max:12000'], 'problem' => ['required', 'string', 'max:6000'], 'solution' => ['required', 'string', 'max:6000'], 'beneficiaries' => ['nullable', 'string', 'max:3000'], 'category' => ['required', 'string', 'max:100'], 'tags_text' => ['nullable', 'string', 'max:400'], 'institution_id' => ['nullable', 'integer', 'exists:institutions,id'], 'visibility' => ['required', Rule::in(['private', 'shared', 'institutional'])], 'status' => ['required', Rule::in(['draft', 'developing'])], 'collaborator_emails' => ['nullable', 'string', 'max:2500'], 'confirm_share' => ['sometimes', 'nullable', 'accepted']];
    }
}

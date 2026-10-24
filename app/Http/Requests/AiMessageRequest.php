<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class AiMessageRequest extends FormRequest
{
    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            throw new HttpResponseException(response()->json(['errors' => $validator->errors()], 422));
        }
        throw new HttpResponseException(redirect()->route('ai.index')->withErrors($validator));
    }

    public function authorize(): bool
    {
        return $this->user()?->status === 'active';
    }

    public function rules(): array
    {
        return [
            'feature' => ['required', Rule::in(array_keys(config('ai.features')))], 'related_id' => ['nullable', 'integer', 'min:1'], 'institution_id' => ['nullable', 'integer', 'min:1'],
            'message' => ['required', 'string', 'max:4000'], 'mode' => ['required', Rule::in(['explore', 'challenge', 'refine', 'plan'])],
            'showcase_ids' => ['nullable', 'array', 'max:5'], 'showcase_ids.*' => ['integer', 'distinct'],
            'target_opportunity_id' => ['nullable', 'integer', 'min:1'],
            'fields' => ['nullable', 'array', 'max:10'], 'fields.*' => ['string', 'max:40', 'distinct'], 'entry_ids' => ['nullable', 'array', 'max:10'], 'entry_ids.*' => ['integer', 'distinct'], 'consent' => ['accepted'],
        ];
    }
}

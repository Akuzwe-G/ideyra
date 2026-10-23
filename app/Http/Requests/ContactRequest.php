<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'category' => ['required', Rule::in(['student', 'institution', 'feedback', 'other'])],
            'subject' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'min:20', 'max:5000'],
            'consent' => ['accepted'],
            'website' => ['nullable', 'string', 'max:0'],
        ];
    }

    public function messages(): array
    {
        return ['website.max' => 'Your submission could not be accepted. Please leave the website field blank.'];
    }
}

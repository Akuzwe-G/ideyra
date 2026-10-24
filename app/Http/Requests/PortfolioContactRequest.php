<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PortfolioContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['sender_name' => 'required|string|max:150', 'sender_email' => 'required|email|max:254', 'message' => 'required|string|min:20|max:3000', 'company' => 'nullable|string|max:0', 'form_token' => 'required|string|max:1500', 'consent' => 'accepted'];
    }
}

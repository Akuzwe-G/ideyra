<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProfessionalProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->status === 'active';
    }

    public function rules(): array
    {
        return ['display_name' => 'required|string|max:150', 'headline' => 'nullable|string|max:200', 'biography' => 'nullable|string|max:4000', 'location' => 'nullable|string|max:150', 'professional_email' => 'nullable|email|max:254', 'phone' => 'nullable|string|max:60', 'links' => 'nullable|array:website,linkedin,github,other', 'links.*' => 'nullable|url:http,https|max:500', 'photo' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:4096|dimensions:max_width=4000,max_height=4000', 'remove_photo' => 'nullable|boolean'];
    }
}

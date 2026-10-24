<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfessionalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->status === 'active';
    }

    public function rules(): array
    {
        $type = $this->input('type');
        $fields = array_keys(config('career.fields.'.(is_string($type) ? $type : ''), []));

        return ['type' => ['required', Rule::in(array_keys(config('career.entry_types')))], 'title' => 'required|string|max:180', 'category' => 'nullable|string|max:80',
            'content' => 'required|array:'.implode(',', $fields).',current', 'content.*' => 'nullable|string|max:6000',
            'content.start' => 'nullable|date_format:Y-m-d', 'content.end' => 'nullable|date_format:Y-m-d|after_or_equal:content.start',
            'content.link' => 'nullable|url:http,https|max:500', 'content.current' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:10000', 'evidence' => 'nullable|file|mimes:pdf,png,jpg,jpeg,webp|max:10240', 'remove_evidence' => 'nullable|boolean'];
    }
}

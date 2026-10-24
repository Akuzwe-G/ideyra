<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CvDocumentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_array($this->input('section_order')) && is_array($this->input('sections'))) {
            $sections = $this->input('sections');
            $order = $this->input('section_order');
            if (count(array_filter($sections, 'is_string')) === count($sections)) {
                $position = fn (string $key): int => is_scalar($order[$key] ?? null) && is_numeric($order[$key]) ? (int) $order[$key] : 999;
                usort($sections, fn ($a, $b) => $position($a) <=> $position($b));
                $this->merge(['sections' => $sections]);
            }
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->status === 'active';
    }

    public function rules(): array
    {
        return ['title' => 'required|string|max:180', 'template' => ['required', Rule::in(array_keys(config('career.templates')))], 'sections' => 'required|array|max:9', 'sections.*' => ['required', 'distinct', Rule::in(array_keys(config('career.sections')))], 'content' => 'required|array:contact,'.implode(',', array_keys(config('career.sections'))), 'content.*' => 'nullable|string|max:15000'];
    }
}

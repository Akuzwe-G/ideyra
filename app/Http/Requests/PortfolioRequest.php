<?php

namespace App\Http\Requests;

use App\Models\ProfessionalProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PortfolioRequest extends FormRequest
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
        return ['slug' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('portfolios', 'slug')->ignore($this->user()?->id ? ProfessionalProfile::where('user_id', $this->user()->id)->first()?->portfolio?->id : null)],
            'theme' => ['required', Rule::in(array_keys(config('career.themes')))],
            'sections' => 'nullable|array|max:8', 'sections.*' => ['required', 'distinct', Rule::in(['biography', 'education', 'skill', 'experience', 'project', 'certification', 'achievement', 'language'])],
            'entry_ids' => 'nullable|array|max:200', 'entry_ids.*' => 'integer|distinct', 'showcase_ids' => 'nullable|array|max:50', 'showcase_ids.*' => 'integer|distinct',
            'contact_fields' => 'nullable|array|max:6', 'contact_fields.*' => 'required|distinct|in:professional_email,phone,location,links,photo',
            'contact_form_enabled' => 'nullable|boolean', 'search_visible' => 'nullable|boolean'];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PublicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->status === 'active';
    }

    protected function prepareForValidation(): void
    {
        foreach (['technologies', 'demo_links', 'interests'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $this->input($key)))))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'project_id' => 'required|integer|exists:projects,id', 'institution_id' => 'required|integer|exists:institutions,id', 'title' => 'required|string|max:180', 'summary' => 'required|string|max:600', 'description' => 'required|string|max:12000', 'problem' => 'required|string|max:6000', 'solution' => 'required|string|max:6000', 'objectives' => 'required|string|max:6000', 'category' => 'required|string|max:100', 'technologies' => 'nullable|array|max:15', 'technologies.*' => 'string|max:50', 'demo_links' => 'nullable|array|max:5', 'demo_links.*' => 'url:http,https|max:2048', 'interests' => 'nullable|array|max:7', 'interests.*' => 'in:technical,research,design,mentorship,business,testing,other',
            'visibility' => 'required|in:private,institution,network,public', 'attribution_ids' => 'nullable|array|max:30', 'attribution_ids.*' => 'integer|distinct', 'institution_attribution' => 'nullable|boolean', 'collaboration_open' => 'nullable|boolean', 'declared_owner' => 'required|string|max:180', 'licence' => 'nullable|string|max:180', 'restrictions' => 'nullable|string|max:3000', 'attribution_requirements' => 'nullable|string|max:2000', 'ip_acknowledgement' => 'accepted', 'publication_consent' => 'accepted', 'version' => 'required|integer|min:0',
            'outcomes' => 'nullable|string|max:4000',
            'status' => 'prohibited', 'approved_by' => 'prohibited', 'approved_snapshot' => 'prohibited',
        ];
    }
}

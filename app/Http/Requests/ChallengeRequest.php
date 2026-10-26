<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->status === 'active';
    }

    public function rules(): array
    {
        return ['institution_id' => 'required|integer|exists:institutions,id', 'title' => 'required|string|max:180', 'summary' => 'required|string|max:600', 'description' => 'required|string|max:12000', 'organiser' => 'required|string|max:180', 'kind' => 'required|in:hackathon,competition,research,entrepreneurship,problem_solving,class_activity', 'rules' => 'required|string|max:12000', 'eligibility_text' => 'required|string|max:6000', 'opens_at' => 'required|date', 'deadline_at' => 'required|date|after:opens_at', 'event_at' => 'nullable|date|after_or_equal:deadline_at', 'visibility' => 'required|in:institution,network,public', 'allow_groups' => 'nullable|boolean', 'allow_cross_institution' => 'nullable|boolean', 'award_information' => 'nullable|string|max:4000', 'criteria' => 'required|array|min:1|max:10', 'criteria.*.name' => 'required|string|max:100', 'criteria.*.weight' => 'required|numeric|min:0.01|max:100', 'confirm' => 'accepted'];
    }
}

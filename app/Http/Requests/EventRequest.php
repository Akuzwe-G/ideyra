<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->status === 'active';
    }

    public function rules(): array
    {
        return ['institution_id' => 'required|integer|exists:institutions,id', 'innovation_challenge_id' => 'nullable|integer|exists:innovation_challenges,id', 'title' => 'required|string|max:180', 'description' => 'required|string|max:12000', 'organiser' => 'required|string|max:180', 'kind' => 'required|in:showcase,demo_day,exhibition,hackathon,workshop,research,fair', 'venue' => 'nullable|string|max:250', 'online_url' => 'nullable|url:http,https|max:2048', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at', 'timezone' => 'required|timezone', 'capacity' => 'nullable|integer|min:1|max:100000', 'registration_rules' => 'required|string|max:4000', 'visibility' => 'required|in:institution,network,public', 'agenda' => 'nullable|array|max:20', 'agenda.*.title' => 'required|string|max:180', 'agenda.*.starts_at' => 'required|date', 'agenda.*.ends_at' => 'required|date', 'confirm' => 'accepted'];
    }
}

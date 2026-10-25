<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ReportScheduleRequest extends IntelligenceFilterRequest
{
    public function rules(): array
    {
        return parent::rules() + ['report_type' => ['required', Rule::in(array_keys(config('intelligence.reports')))], 'format' => 'required|in:pdf,xlsx,csv', 'frequency' => 'required|in:weekly,monthly,quarterly', 'recipient_ids' => 'required|array|min:1|max:20', 'recipient_ids.*' => 'integer|distinct', 'confirm' => 'accepted'];
    }
}

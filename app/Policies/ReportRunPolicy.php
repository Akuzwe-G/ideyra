<?php

namespace App\Policies;

use App\Models\ReportRun;
use App\Models\User;
use App\Support\IntelligenceAccess;
use App\Support\IntelligenceFilters;

class ReportRunPolicy
{
    public function view(User $user, ReportRun $run): bool
    {
        if ($user->status !== 'active' || $run->expires_at->isPast() || ($run->user_id !== $user->id && ! in_array($user->id, $run->recipient_ids ?? [], true))) {
            return false;
        }
        try {
            $f = app(IntelligenceFilters::class)->normalise($user, $run->filters);
            $context = app(IntelligenceAccess::class)->context($user, $f, 'reports.view_history');
            app(IntelligenceAccess::class)->context($user, $f, 'analytics.export');
            if ($run->report_type === 'kpis' && ! IntelligenceAccess::kpi($user, (int) $run->institution_id, 'kpis.view')) {
                return false;
            }
            $old = $run->access_scope['class_ids'] ?? null;
            $current = $context['class_ids'];

            return $old === null ? $current === null : ($current === null || ! array_diff($old, $current));
        } catch (\Throwable) {
            return false;
        }
    }

    public function download(User $user, ReportRun $run): bool
    {
        return $run->status === 'ready' && $this->view($user, $run);
    }
}

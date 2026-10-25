<?php

namespace App\Http\Controllers;

use App\Http\Requests\IntelligenceFilterRequest;
use App\Models\AchievementVerification;
use App\Models\Institution;
use App\Models\User;
use App\Support\Audit;
use App\Support\CareerAccess;
use App\Support\InnovationAccess;
use App\Support\IntelligenceAccess;
use App\Support\IntelligenceFilters;
use App\Support\IntelligenceMetrics;
use App\Support\IntelligenceReports;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IntelligenceController extends Controller
{
    public function index(IntelligenceFilterRequest $request): View
    {
        $filters = app(IntelligenceFilters::class)->normalise($request->user(), $request->validated());
        $context = app(IntelligenceAccess::class)->context($request->user(), $filters);
        if ($filters['section'] === 'quality') {
            app(IntelligenceAccess::class)->context($request->user(), $filters, 'data_quality.view');
        }
        $data = app(IntelligenceMetrics::class)->cached($context, $filters);
        $institution = $context['institution_id'] ? Institution::findOrFail($context['institution_id']) : null;
        $options = app(IntelligenceFilters::class)->options($request->user(), $filters);

        return view('intelligence.dashboard', compact('data', 'filters', 'context', 'institution', 'options'));
    }

    public function records(IntelligenceFilterRequest $request, string $type): View
    {
        $filters = app(IntelligenceFilters::class)->normalise($request->user(), $request->validated());
        $context = app(IntelligenceAccess::class)->context($request->user(), $filters);
        $service = app(IntelligenceMetrics::class);
        $institution = $context['institution_id'] ? Institution::findOrFail($context['institution_id']) : null;
        $quality = $request->validate(['quality' => 'nullable|in:idea_submission,ownership,unknown_status,completion_time,academic_context,invalid_dates,assignments,verification,duplicates'])['quality'] ?? null;
        if ($quality) {
            app(IntelligenceAccess::class)->context($request->user(), $filters, 'data_quality.view');
        }
        if ($type === 'projects') {
            $ids = app(IntelligenceFilters::class)->window($service->projects($context, $filters), $filters, 'p.created_at')->select('p.id');
            match ($quality) {
                'ownership' => $ids->whereNotExists(fn ($q) => $q->selectRaw('1')->from('users')->whereColumn('users.id', 'p.owner_id')),
                'unknown_status' => $ids->whereNotIn('p.status', ['draft', 'pending_approval', 'approved', 'in_progress', 'on_hold', 'completed', 'cancelled', 'archived']),
                'completion_time' => $ids->where('p.status', 'completed')->whereNull('p.completed_at'),
                'academic_context' => $ids->whereNull('p.academic_class_id'),
                'invalid_dates' => $ids->where(fn ($q) => $q->whereColumn('p.target_at', '<', 'p.starts_at')->orWhereColumn('p.completed_at', '<', 'p.starts_at')),
                default => null,
            };
            $records = InnovationAccess::projects($request->user())->whereIn('projects.id', $ids)->orderByDesc('projects.id')->paginate(20)->withQueryString();
        } elseif ($type === 'ideas') {
            $ids = app(IntelligenceFilters::class)->window($service->ideas($context, $filters), $filters, 'i.created_at')->select('i.id');
            if ($quality === 'idea_submission') {
                $ids->whereIn('i.status', ['submitted', 'under_review', 'approved', 'returned', 'converted'])->whereNull('i.first_submitted_at');
            }
            $records = InnovationAccess::ideas($request->user())->whereIn('ideas.id', $ids)->orderByDesc('ideas.id')->paginate(20)->withQueryString();
        } elseif ($type === 'members') {
            abort_unless($institution && InnovationAccess::can($institution, 'members.view', $request->user()), 403);
            $ids = $service->students($context, $filters);
            if ($quality === 'assignments') {
                $ids->whereNotExists(fn ($q) => $q->selectRaw('1')->from('class_assignments as ca')->whereColumn('ca.membership_id', 'm.id')->where('ca.assignment', 'student'));
            }
            if ($quality === 'duplicates') {
                $ids->whereIn('m.user_id', DB::table('memberships')->where('institution_id', $institution->id)->select('user_id')->groupBy('user_id')->havingRaw('COUNT(*)>1'));
            }
            $records = User::whereIn('id', $ids)->orderBy('name')->paginate(20)->withQueryString();
        } elseif ($type === 'verifications') {
            abort_unless($institution && CareerAccess::report($institution, $request->user()), 403);
            $query = AchievementVerification::where('institution_id', $institution->id)->whereIn('user_id', $service->students($context, $filters));
            $from = CarbonImmutable::parse($filters['from'], config('intelligence.timezone'))->utc();
            $to = CarbonImmutable::parse($filters['to'], config('intelligence.timezone'))->addDay()->utc();
            if ($quality === 'verification') {
                $query->where('status', 'verified')->where(fn ($q) => $q->whereNull('approved_content')->orWhereNull('verified_by')->orWhereNull('verified_at'))->where('created_at', '>=', $from)->where('created_at', '<', $to);
            } else {
                $query->where('verified_at', '>=', $from)->where('verified_at', '<', $to);
            }
            $records = $query->orderByDesc('id')->paginate(20)->withQueryString();
        } else {
            abort(404);
        }

        return view('intelligence.records', compact('records', 'type', 'filters', 'context', 'institution'));
    }

    public function csv(IntelligenceFilterRequest $request, string $chart): StreamedResponse
    {
        $filters = app(IntelligenceFilters::class)->normalise($request->user(), $request->validated());
        $context = app(IntelligenceAccess::class)->context($request->user(), $filters, 'analytics.export');
        $data = app(IntelligenceMetrics::class)->cached($context, $filters);
        $selected = collect($data['charts'])->firstWhere('key', $chart);
        abort_unless($selected, 404);
        Audit::record('intelligence.chart_exported', $request->user(), $context['institution_id'], ['chart' => $chart, 'filters' => $filters]);

        return response()->streamDownload(function () use ($selected): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Category', $selected['unit'], 'Privacy note'], ',', '"', '');
            foreach ($selected['labels'] as $i => $label) {
                fputcsv($stream, [IntelligenceReports::cell($label), $selected['values'][$i] ?? 'Protected / unavailable', $selected['protected'] ? 'Small-group suppression applied' : ''], ',', '"', '');
            }
            fclose($stream);
        }, 'ideyra-intelligence-'.$chart.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}

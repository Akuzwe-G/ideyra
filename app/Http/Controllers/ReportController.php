<?php

namespace App\Http\Controllers;

use App\Http\Requests\IntelligenceFilterRequest;
use App\Http\Requests\ReportScheduleRequest;
use App\Jobs\GenerateIntelligenceReport;
use App\Models\Institution;
use App\Models\InstitutionKpi;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Support\Audit;
use App\Support\IntelligenceAccess;
use App\Support\IntelligenceFilters;
use App\Support\IntelligenceReports;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    private function prepare(Request $request, string $permission): array
    {
        $filters = app(IntelligenceFilters::class)->normalise($request->user(), $request->all());
        $context = app(IntelligenceAccess::class)->context($request->user(), $filters, $permission);
        $institution = $context['institution_id'] ? Institution::findOrFail($context['institution_id']) : null;
        $options = app(IntelligenceFilters::class)->options($request->user(), $filters);

        return compact('filters', 'context', 'institution', 'options');
    }

    public function index(IntelligenceFilterRequest $request): View
    {
        $data = $this->prepare($request, 'reports.generate');
        app(IntelligenceAccess::class)->context($request->user(), $data['filters'], 'analytics.export');

        return view('intelligence.reports', $data + ['preview' => null]);
    }

    public function preview(IntelligenceFilterRequest $request): View
    {
        $request->validate(['report_type' => ['required', Rule::in(array_keys(config('intelligence.reports')))]]);
        $data = $this->prepare($request, 'reports.generate');
        $preview = app(IntelligenceReports::class)->preview($request->user(), $data['filters'], $request->string('report_type')->toString());

        return view('intelligence.reports', $data + compact('preview'));
    }

    public function generate(Request $request): RedirectResponse
    {
        $data = $request->validate(['report_type' => ['required', Rule::in(array_keys(config('intelligence.reports')))], 'format' => 'required|in:pdf,xlsx,csv', 'processing' => 'required|in:now,queue', 'confirm' => 'accepted', 'recipient_ids' => 'nullable|array|max:20', 'recipient_ids.*' => 'integer|distinct']);
        $filters = app(IntelligenceFilters::class)->normalise($request->user(), $request->all());
        $run = app(IntelligenceReports::class)->request($request->user(), $filters, $data['report_type'], $data['format'], $data['recipient_ids'] ?? []);
        $large = $data['report_type'] === 'kpis' && InstitutionKpi::where('institution_id', $run->institution_id)->count() > 100;
        if ($data['processing'] === 'queue' || $large) {
            GenerateIntelligenceReport::dispatch($run->id)->onQueue('intelligence-reports');
            $message = 'Report queued. A configured intelligence-reports worker is required. Manual generation remains available for bounded reports.';
        } else {
            GenerateIntelligenceReport::dispatchSync($run->id);
            $message = $run->fresh()->status === 'ready' ? 'A new immutable report snapshot is ready.' : 'Report generation failed safely. Review the scope or try a new request; no confidential error details are exposed.';
        }

        return redirect()->route('reports.show', $run)->with('status', $message);
    }

    public function history(IntelligenceFilterRequest $request): View
    {
        $data = $this->prepare($request, 'reports.view_history');
        $runs = ReportRun::where(fn ($q) => $q->where('user_id', $request->user()->id)->orWhereJsonContains('recipient_ids', $request->user()->id))->where('institution_id', $data['context']['institution_id'])->orderByDesc('id')->paginate(20)->withQueryString();

        return view('intelligence.report-history', $data + compact('runs'));
    }

    public function show(Request $request, ReportRun $run): View
    {
        Gate::authorize('view', $run);
        $institution = $run->institution_id ? Institution::findOrFail($run->institution_id) : null;

        return view('intelligence.report-show', compact('run', 'institution'));
    }

    public function download(Request $request, ReportRun $run): BinaryFileResponse
    {
        Gate::authorize('download', $run);
        abort_unless($run->path && Storage::disk('local')->exists($run->path), 404);
        $path = Storage::disk('local')->path($run->path);
        abort_unless(hash_equals($run->file_hash, hash_file('sha256', $path)) && hash_equals($run->snapshot_hash, hash('sha256', json_encode($run->snapshot, JSON_THROW_ON_ERROR))), 409, 'This report failed its integrity check.');
        Audit::record('report.downloaded', $run, $run->institution_id, ['format' => $run->format]);

        return response()->download($path, 'ideyra-'.$run->report_type.'-'.$run->identifier.'.'.$run->format, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function schedules(IntelligenceFilterRequest $request): View
    {
        $data = $this->prepare($request, 'reports.schedule');
        app(IntelligenceAccess::class)->context($request->user(), $data['filters'], 'reports.generate');
        $schedules = ReportSchedule::where('user_id', $request->user()->id)->where('institution_id', $data['context']['institution_id'])->orderByDesc('id')->paginate(15)->withQueryString();
        $recipients = User::where('status', 'active')->when($data['context']['institution_id'], fn ($q, $id) => $q->whereHas('memberships', fn ($m) => $m->where('institution_id', $id)->where('status', 'active')->whereHas('role', fn ($r) => $r->where('name', '!=', 'Student'))), fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', 'System Administrator')->where('scope', 'platform')))->orderBy('name')->limit(100)->get(['id', 'name']);

        return view('intelligence.schedules', $data + compact('schedules', 'recipients'));
    }

    public function schedule(ReportScheduleRequest $request): RedirectResponse
    {
        $filters = app(IntelligenceFilters::class)->normalise($request->user(), $request->validated());
        if ($request->input('report_type') === 'network') {
            $filters['section'] = 'network';
        }
        $context = app(IntelligenceAccess::class)->context($request->user(), $filters, 'reports.schedule');
        app(IntelligenceAccess::class)->context($request->user(), $filters, 'reports.generate');
        app(IntelligenceAccess::class)->context($request->user(), $filters, 'analytics.export');
        $d = $request->validated();
        abort_if($d['report_type'] === 'platform' && ! $context['platform'], 403);
        if ($d['report_type'] === 'kpis') {
            abort_unless($context['institution_id'] && IntelligenceAccess::kpi($request->user(), $context['institution_id'], 'kpis.view'), 403);
        }
        $ids = app(IntelligenceReports::class)->recipients($request->user(), $filters, $d['recipient_ids'], $d['report_type']);
        $schedule = ReportSchedule::create(['user_id' => $request->user()->id, 'institution_id' => $context['institution_id'], 'report_type' => $d['report_type'], 'frequency' => $d['frequency'], 'format' => $d['format'], 'filters' => $filters, 'recipient_ids' => $ids, 'next_run_at' => app(IntelligenceReports::class)->next($d['frequency']), 'enabled' => true]);
        Audit::record('report.schedule_created', $schedule, $context['institution_id'], ['frequency' => $d['frequency']]);

        return redirect()->route('reports.schedules', $filters)->with('status', 'Schedule saved. Scheduler and queue operation must be configured; email delivery is not assumed.');
    }

    public function toggle(Request $request, ReportSchedule $schedule): RedirectResponse
    {
        abort_unless($schedule->user_id === $request->user()->id, 403);
        $filters = app(IntelligenceFilters::class)->normalise($request->user(), $schedule->filters);
        app(IntelligenceAccess::class)->context($request->user(), $filters, 'reports.schedule');
        $d = $request->validate(['enabled' => 'required|boolean', 'confirm' => 'accepted']);
        $schedule->update(['enabled' => $d['enabled']]);
        Audit::record('report.schedule_updated', $schedule, $schedule->institution_id, ['enabled' => (bool) $d['enabled']]);

        return redirect()->route('reports.schedules', $filters)->with('status', 'Schedule updated.');
    }
}

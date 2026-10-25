<?php

namespace App\Http\Controllers;

use App\Http\Requests\KpiRequest;
use App\Models\Institution;
use App\Models\InstitutionKpi;
use App\Models\KpiChange;
use App\Models\KpiObservation;
use App\Models\ReportingSetting;
use App\Models\User;
use App\Support\Audit;
use App\Support\IntelligenceAccess;
use App\Support\IntelligenceFilters;
use App\Support\KpiWorkflow;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class KpiController extends Controller
{
    public function index(Request $request, Institution $institution): View
    {
        abort_unless(IntelligenceAccess::kpi($request->user(), $institution->id, 'kpis.view'), 403);
        $f = $request->validate(['category' => 'nullable|string|max:80', 'lifecycle' => 'nullable|in:draft,active,closed', 'sort' => 'nullable|in:title,period,target', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $q = InstitutionKpi::where('institution_id', $institution->id)->with('owner')->with(['changes' => fn ($query) => $query->limit(20), 'observations' => fn ($query) => $query->limit(20)])->withCount('changes', 'observations');
        foreach (['category', 'lifecycle'] as $key) {
            if (! empty($f[$key])) {
                $q->where($key, $f[$key]);
            }
        }
        if (! empty($f['from'])) {
            $q->where('period_to', '>=', $f['from']);
        }if (! empty($f['to'])) {
            $q->where('period_from', '<=', $f['to']);
        }
        $q->orderBy('category')->orderBy(match ($f['sort'] ?? 'title') {
            'period' => 'period_from','target' => 'target',default => 'title'
        });
        $kpis = $q->paginate(12)->withQueryString();
        $workflow = app(KpiWorkflow::class);
        $scores = $kpis->getCollection()->mapWithKeys(fn ($kpi) => [$kpi->id => $workflow->score($request->user(), $kpi)]);
        $categories = InstitutionKpi::where('institution_id', $institution->id)->distinct()->pluck('category');
        $settings = ReportingSetting::firstOrNew(['institution_id' => $institution->id], ['require_kpi_approval' => true]);

        return view('intelligence.kpis', compact('institution', 'kpis', 'scores', 'categories', 'settings'));
    }

    public function form(Request $request, Institution $institution, ?InstitutionKpi $kpi = null): View
    {
        abort_unless(IntelligenceAccess::kpi($request->user(), $institution->id, $kpi ? 'kpis.edit' : 'kpis.create'), 403);
        if ($kpi) {
            abort_unless($kpi->institution_id === $institution->id, 404);
        }
        $kpi ??= new InstitutionKpi(['institution_id' => $institution->id, 'owner_id' => $request->user()->id, 'source' => 'automatic', 'metric' => 'completed_projects', 'unit' => 'Projects', 'direction' => 'increase', 'target' => 1, 'period_from' => today(config('intelligence.timezone'))->startOfMonth(), 'period_to' => today(config('intelligence.timezone'))->endOfMonth(), 'version' => 0]);
        $owners = User::whereHas('memberships', fn ($q) => $q->where('institution_id', $institution->id)->where('status', 'active'))->where('status', 'active')->orderBy('name')->get(['id', 'name']);
        $filters = app(IntelligenceFilters::class)->normalise($request->user(), ['institution_id' => $institution->id]);
        $options = app(IntelligenceFilters::class)->options($request->user(), $filters);

        return view('intelligence.kpi-form', compact('institution', 'kpi', 'owners', 'options'));
    }

    public function save(KpiRequest $request, Institution $institution, ?InstitutionKpi $kpi = null): RedirectResponse
    {
        app(KpiWorkflow::class)->save($request->user(), $institution, $request->validated(), $kpi);

        return redirect()->route('kpis.index', $institution)->with('status', $kpi ? 'KPI update recorded. Sensitive active changes may await independent approval.' : 'KPI draft created. Review and activate it when ready.');
    }

    public function action(Request $request, InstitutionKpi $kpi): RedirectResponse
    {
        $d = $request->validate(['action' => 'required|in:activate,close', 'reason' => 'required|string|max:2000', 'confirm' => 'accepted']);
        app(KpiWorkflow::class)->action($request->user(), $kpi, $d['action'], $d['reason']);

        return redirect()->route('kpis.index', $kpi->institution_id)->with('status', 'KPI lifecycle request recorded.');
    }

    public function decide(Request $request, KpiChange $change): RedirectResponse
    {
        $d = $request->validate(['decision' => 'required|in:approve,reject', 'reason' => 'required|string|max:2000', 'confirm' => 'accepted']);
        app(KpiWorkflow::class)->decide($request->user(), $change, $d['decision'] === 'approve', $d['reason']);

        return redirect()->route('kpis.index', $change->kpi->institution_id)->with('status', 'Independent KPI decision recorded.');
    }

    public function observe(Request $request, InstitutionKpi $kpi): RedirectResponse
    {
        $d = $request->validate(['value' => 'required|numeric|min:0|max:999999999999', 'observed_on' => 'required|date_format:Y-m-d', 'evidence' => 'required|string|max:4000', 'file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120', 'confirm' => 'accepted']);
        app(KpiWorkflow::class)->observe($request->user(), $kpi, $d, $request->file('file'));

        return redirect()->route('kpis.index', $kpi->institution_id)->with('status', 'Manual observation recorded with evidence and attribution.');
    }

    public function evidence(Request $request, KpiObservation $observation): BinaryFileResponse
    {
        $kpi = InstitutionKpi::findOrFail($observation->institution_kpi_id);
        Gate::authorize('view', $kpi);
        abort_unless($observation->evidence_path && Storage::disk('local')->exists($observation->evidence_path), 404);

        Audit::record('kpi.evidence_downloaded', $kpi, $kpi->institution_id, ['observation_id' => $observation->id], $request->user()->id);

        return response()->download(Storage::disk('local')->path($observation->evidence_path), 'kpi-evidence.'.pathinfo($observation->evidence_path, PATHINFO_EXTENSION), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function policy(Request $request, Institution $institution): RedirectResponse
    {
        abort_unless(IntelligenceAccess::kpi($request->user(), $institution->id, 'kpis.approve'), 403);
        $d = $request->validate(['require_kpi_approval' => 'required|boolean', 'reason' => 'required|string|max:2000', 'confirm' => 'accepted']);
        $setting = ReportingSetting::updateOrCreate(['institution_id' => $institution->id], ['require_kpi_approval' => $d['require_kpi_approval']]);
        Audit::record('kpi.approval_policy_updated', $setting, $institution->id, ['require_approval' => (bool) $d['require_kpi_approval'], 'reason' => $d['reason']]);

        return redirect()->route('kpis.index', $institution)->with('status', 'Approval policy updated. In-progress sensitive changes still require independent approval.');
    }
}

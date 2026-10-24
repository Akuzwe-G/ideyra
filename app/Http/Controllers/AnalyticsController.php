<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use App\Support\CareerAnalytics;
use App\Support\CareerWorkflow;
use App\Support\DashboardAnalytics;
use App\Support\InnovationAccess;
use App\Support\InstitutionAccess;
use App\Support\OpportunityAnalytics;
use App\Support\ProjectAnalytics;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    public function system(Request $request, string $chart, DashboardAnalytics $analytics): StreamedResponse
    {
        InstitutionAccess::requireSystem();
        $filters = $analytics->filters($request);

        return $this->csv($analytics->system($filters), $chart, $filters);
    }

    public function institution(Request $request, Institution $institution, string $chart, DashboardAnalytics $analytics): StreamedResponse
    {
        abort_unless(DashboardAnalytics::canViewInstitution($institution), 403);
        $filters = $analytics->filters($request);

        return $this->csv($analytics->institution($institution, $filters), $chart, $filters);
    }

    public function personal(Request $request, string $chart, DashboardAnalytics $analytics): StreamedResponse
    {
        $filters = $analytics->filters($request);
        $request->validate(['supervision' => 'nullable|boolean']);

        return $this->csv(app(ProjectAnalytics::class)->data($filters, null, $request->boolean('supervision')), $chart, $filters);
    }

    public function opportunities(Request $request, ?string $chart = null): View|StreamedResponse
    {
        $data = $request->validate(['scope' => 'nullable|in:personal,system,institution', 'institution_id' => 'nullable|integer|exists:institutions,id']);
        $scope = $data['scope'] ?? 'personal';
        $institution = $scope === 'institution' ? Institution::findOrFail($data['institution_id'] ?? 0) : null;
        $filters = app(DashboardAnalytics::class)->filters($request);
        $analytics = app(OpportunityAnalytics::class)->data($filters, $scope, $institution);
        if ($chart) {
            return $this->csv($analytics, $chart, $filters);
        }
        $memberships = InnovationAccess::memberships($request->user());

        return view('opportunities.analytics', compact('analytics', 'filters', 'scope', 'institution', 'memberships'));
    }

    public function career(Request $request, string $chart): StreamedResponse
    {
        $request->validate(['institution_id' => 'nullable|integer|exists:institutions,id']);
        $institution = $request->filled('institution_id') ? Institution::findOrFail($request->integer('institution_id')) : null;
        $filters = app(DashboardAnalytics::class)->filters($request);
        $data = app(CareerAnalytics::class)->data(app(CareerWorkflow::class)->profile(), $filters, $institution);

        return $this->csv($data, $chart, $filters);
    }

    private function csv(array $data, string $key, array $filters): StreamedResponse
    {
        $chart = collect($data['charts'])->firstWhere('key', $key);
        abort_unless($chart, 404);

        return response()->streamDownload(function () use ($chart, $filters): void {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Label', $chart['unit'], 'Percentage of series', 'From', 'To', 'Grouping'], ',', '"', '');
            $total = array_sum($chart['values']);
            foreach ($chart['labels'] as $index => $label) {
                $label = preg_match('/^[\s]*[=+@\-]/u', $label) ? "'".$label : $label;
                $count = $chart['values'][$index];
                fputcsv($file, [$label, $count, $total ? round($count / $total * 100, 2) : 0, $filters['from'], $filters['to'], $chart['type'] === 'line' ? $filters['group'] : 'category'], ',', '"', '');
            }
            fclose($file);
        }, 'ideyra-'.$key.'-'.$filters['from'].'-'.$filters['to'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}

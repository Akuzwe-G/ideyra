<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use App\Support\DashboardAnalytics;
use App\Support\InstitutionAccess;
use Illuminate\Http\Request;
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

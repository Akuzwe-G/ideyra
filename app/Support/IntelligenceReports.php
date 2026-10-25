<?php

namespace App\Support;

use App\Jobs\GenerateIntelligenceReport;
use App\Models\Institution;
use App\Models\InstitutionKpi;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Notifications\IntelligenceReportReady;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;

class IntelligenceReports
{
    public static function cell(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[\s=+\-@]/u', $value) ? "'".$value : $value;
    }

    public static function emailOperational(): bool
    {
        return config('intelligence.email_enabled') && config('intelligence.scheduler_verified') && config('intelligence.queue_verified') && ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    public function recipients(User $user, array $filters, array $ids, string $type = 'overview'): array
    {
        $source = app(IntelligenceAccess::class)->context($user, $filters);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        abort_if(count($ids) > 20, 422, 'Select at most twenty recipients.');
        foreach ($ids as $id) {
            $recipient = User::whereKey($id)->where('status', 'active')->firstOrFail();
            if (! empty($filters['institution_id']) && ! InstitutionAccess::system($recipient)) {
                abort_unless(InstitutionAccess::membership(Institution::findOrFail($filters['institution_id']), $recipient), 422, 'Recipients must belong to the report institution.');
            }
            $f = app(IntelligenceFilters::class)->normalise($recipient, $filters);
            $target = app(IntelligenceAccess::class)->context($recipient, $f, 'reports.view_history');
            app(IntelligenceAccess::class)->context($recipient, $f, 'analytics.export');
            abort_unless($target['class_ids'] === null || ($source['class_ids'] !== null && ! array_diff($source['class_ids'], $target['class_ids'])), 422, 'Recipients must have access to the complete report scope.');
            if ($type === 'kpis') {
                abort_unless(IntelligenceAccess::kpi($recipient, (int) $filters['institution_id'], 'kpis.view'), 422, 'Recipients require KPI access.');
            }
        }

        return $ids;
    }

    public function request(User $user, array $filters, string $type, string $format, array $recipients = [], ?ReportSchedule $schedule = null, ?string $scheduledFor = null): ReportRun
    {
        abort_unless(array_key_exists($type, config('intelligence.reports')) && in_array($format, ['pdf', 'xlsx', 'csv'], true), 422);
        $filters = app(IntelligenceFilters::class)->normalise($user, $filters);
        $context = app(IntelligenceAccess::class)->context($user, $filters, 'reports.generate');
        app(IntelligenceAccess::class)->context($user, $filters, 'analytics.export');
        abort_if($type === 'platform' && ! $context['platform'], 403);
        if ($type === 'kpis') {
            abort_unless($context['institution_id'] && IntelligenceAccess::kpi($user, $context['institution_id'], 'kpis.view'), 403);
        }
        $ids = $this->recipients($user, $filters, $recipients, $type);
        $scope = $context;
        if ($scope['class_ids'] !== null) {
            $scope['class_ids'] = app(IntelligenceFilters::class)->classes($context, $filters)->pluck('c.id')->map(fn ($v) => (int) $v)->all();
        }
        $run = ReportRun::create(['identifier' => (string) Str::ulid(), 'user_id' => $user->id, 'institution_id' => $context['institution_id'], 'report_type' => $type, 'format' => $format, 'filters' => $filters, 'access_scope' => $scope, 'recipient_ids' => $ids, 'definition_version' => config('intelligence.version'), 'status' => 'pending', 'expires_at' => now()->addDays(config('intelligence.retention_days')), 'report_schedule_id' => $schedule?->id, 'scheduled_for' => $scheduledFor]);
        Audit::record('report.requested', $run, $run->institution_id, ['type' => $type, 'format' => $format], $user->id);

        return $run;
    }

    public function preview(User $user, array $filters, string $type): array
    {
        abort_unless(array_key_exists($type, config('intelligence.reports')), 422);
        $filters = app(IntelligenceFilters::class)->normalise($user, $filters);
        $context = app(IntelligenceAccess::class)->context($user, $filters, 'reports.generate');
        app(IntelligenceAccess::class)->context($user, $filters, 'analytics.export');
        abort_if($type === 'platform' && ! $context['platform'], 403);
        $filters['section'] = in_array($type, ['overview', 'platform', 'kpis'], true) ? 'overview' : $type;
        $data = app(IntelligenceMetrics::class)->cached($context, $filters);
        if (! IntelligenceAccess::permits($user, $filters, 'data_quality.view')) {
            $data['quality'] = [];
        }
        if ($type === 'kpis') {
            abort_unless($context['institution_id'] && IntelligenceAccess::kpi($user, $context['institution_id'], 'kpis.view'), 403);
            $data['kpis'] = [];
            $workflow = app(KpiWorkflow::class);
            InstitutionKpi::where('institution_id', $context['institution_id'])->where('period_from', '<=', $filters['to'])->where('period_to', '>=', $filters['from'])->with('owner')->orderBy('id')->chunkById(100, function ($kpis) use (&$data, $workflow, $user): void {
                foreach ($kpis as $kpi) {
                    $score = $workflow->score($user, $kpi);
                    $data['kpis'][] = ['title' => $kpi->title, 'category' => $kpi->category, 'source' => $kpi->source, 'metric' => $kpi->metric, 'target' => $kpi->target, 'upper' => $kpi->target_upper, 'direction' => $kpi->direction, 'unit' => $kpi->unit, 'owner' => $kpi->owner->name, 'period' => $kpi->period_from->toDateString().' to '.$kpi->period_to->toDateString(), 'lifecycle' => $kpi->lifecycle] + $score;
                }
            });
        }
        $institution = $context['institution_id'] ? Institution::findOrFail($context['institution_id']) : null;

        return ['title' => config('intelligence.reports')[$type], 'type' => $type, 'institution' => $institution?->name ?? 'Ideyra platform', 'institution_logo' => $this->logo($institution), 'filters' => $filters, 'data' => $data, 'generated_at' => now()->timezone(config('intelligence.timezone'))->toIso8601String(), 'definition_version' => config('intelligence.version'), 'confidentiality' => 'CONFIDENTIAL · authorised aggregate reporting', 'privacy' => 'Counts of people from 1 to '.(config('intelligence.small_group') - 1).' are suppressed; affected demographic breakdowns are protected in full. No private CV, conversation, portfolio draft or evidence contents are included.'];
    }

    private function logo(?Institution $institution): ?string
    {
        if (! $institution?->logo_path || ! Storage::disk('local')->exists($institution->logo_path)) {
            return null;
        }
        $path = Storage::disk('local')->path($institution->logo_path);
        $mime = mime_content_type($path);

        return in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true) && filesize($path) <= 2097152 ? 'data:'.$mime.';base64,'.base64_encode(file_get_contents($path)) : null;
    }

    public function generate(ReportRun $run): void
    {
        $lock = Cache::lock('report:'.$run->id, 240);
        if (! $lock->get()) {
            return;
        }
        try {
            $run->refresh();
            if ($run->status === 'ready' || $run->expires_at->isPast()) {
                return;
            }
            $user = User::whereKey($run->user_id)->where('status', 'active')->firstOrFail();
            $filters = app(IntelligenceFilters::class)->normalise($user, $run->filters);
            $current = app(IntelligenceAccess::class)->context($user, $filters, 'reports.generate');
            $savedClasses = $run->access_scope['class_ids'] ?? null;
            if ($savedClasses !== null) {
                abort_unless($current['class_ids'] !== null && ! array_diff($savedClasses, $current['class_ids']), 403);
                $filters['academic_class_id'] = $filters['academic_class_id'] ?? null;
            }
            $effective = $current['class_ids'] === null ? null : app(IntelligenceFilters::class)->classes($current, $filters)->pluck('c.id')->map(fn ($v) => (int) $v)->all();
            abort_unless($effective === $savedClasses, 403, 'Report scope changed. Submit a new request.');
            $run->update(['status' => 'generating', 'failure_code' => null]);
            $payload = $this->preview($user, $filters, $run->report_type);
            $path = 'reporting/exports/'.$run->identifier.'.'.$run->format;
            Storage::disk('local')->makeDirectory('reporting/exports');
            $absolute = Storage::disk('local')->path($path);
            if (Storage::disk('local')->exists($path)) {
                throw new \RuntimeException('immutable_file_conflict');
            }
            if ($run->format === 'pdf') {
                $pdf = Pdf::loadView('intelligence.report-document', ['report' => $payload])->setPaper('a4')->setOptions(['defaultFont' => 'DejaVu Sans', 'defaultMediaType' => 'print', 'isRemoteEnabled' => false, 'isPhpEnabled' => false]);
                $pdf->save($absolute);
            } elseif ($run->format === 'xlsx') {
                $writer = new Writer;
                $writer->openToFile($absolute);
                $writer->setCreator('Ideyra');
                try {
                    foreach ($this->rows($payload) as $i => $row) {
                        $style = $i === 0 ? (new Style)->setFontBold()->setFontSize(15) : null;
                        $writer->addRow(Row::fromValues(array_map([self::class, 'cell'], $row), $style));
                    }
                } finally {
                    $writer->close();
                }
            } else {
                $file = fopen($absolute, 'xb');
                if (! $file) {
                    throw new \RuntimeException('storage_unavailable');
                }
                try {
                    foreach ($this->rows($payload) as $row) {
                        fputcsv($file, array_map([self::class, 'cell'], $row), ',', '"', '');
                    }
                } finally {
                    fclose($file);
                }
            }
            $run->update(['snapshot' => $payload, 'snapshot_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 'path' => $path, 'file_hash' => hash_file('sha256', $absolute), 'generated_at' => now(), 'status' => 'ready']);
            Audit::record('report.generated', $run, $run->institution_id, ['format' => $run->format, 'definition_version' => $run->definition_version], $user->id);
            foreach (array_unique([$user->id, ...$run->recipient_ids]) as $id) {
                $recipient = User::find($id);
                if ($recipient && Gate::forUser($recipient)->allows('view', $run)) {
                    $recipient->notify(new IntelligenceReportReady($run->id));
                }
            }
        } catch (Throwable $error) {
            if (isset($path) && Storage::disk('local')->exists($path) && $run->status !== 'ready') {
                Storage::disk('local')->delete($path);
            }
            if ($run->status !== 'ready') {
                $run->update(['status' => 'failed', 'failure_code' => 'generation_failed']);
                Audit::record('report.failed', $run, $run->institution_id, ['failure_class' => class_basename($error)], $run->user_id);
            }
        } finally {
            $lock->release();
        }
    }

    /** @return \Generator<int,array<int,string|int|float|null>> */
    public function rows(array $report): \Generator
    {
        yield [$report['title']];
        yield ['Institution / scope', $report['institution']];
        yield ['Reporting period', $report['filters']['from'], $report['filters']['to']];
        yield ['Timezone', config('intelligence.timezone')];
        yield ['Generated', $report['generated_at']];
        yield ['Definitions', $report['definition_version']];
        yield ['Confidentiality', $report['confidentiality']];
        yield ['Privacy', $report['privacy']];
        yield ['Filters', json_encode($report['filters'])];
        yield [];
        yield ['Metric', 'Value', 'Unit', 'Definition'];
        foreach ($report['data']['selected'] as $m) {
            yield [$m['label'], $m['value'] ?? 'Protected / unavailable', $m['unit'], $m['definition']];
        }
        foreach ($report['data']['charts'] as $chart) {
            yield [];
            yield [$chart['title'], $chart['note']];
            yield ['Category', $chart['unit']];
            foreach ($chart['labels'] as $i => $label) {
                yield [$label, $chart['values'][$i] ?? 'Protected / unavailable'];
            }
        }
        if (! empty($report['data']['kpis'])) {
            yield [];
            yield ['KPI', 'Target', 'Upper bound', 'Actual', 'Unit', 'Direction', 'Achievement %', 'Performance', 'Lifecycle', 'Source', 'Owner', 'Period', 'Previous actual', 'Trend change'];
            foreach ($report['data']['kpis'] as $kpi) {
                yield [$kpi['title'], $kpi['target'], $kpi['upper'], $kpi['actual'] ?? 'Protected / unavailable', $kpi['unit'], $kpi['direction'], $kpi['achievement'], $kpi['performance'], $kpi['lifecycle'], $kpi['source'], $kpi['owner'], $kpi['period'], $kpi['previous'], $kpi['trend']];
            }
        }
        foreach ($report['data']['institutions'] ?? [] as $row) {
            yield ['Institution comparison', $row['name'], $row['students'] ?? 'Protected / unavailable', $row['projects'], $row['completions'], $row['applications'], $row['participants'] ?? 'Protected / unavailable', $row['participation_rate'] ?? 'Protected / unavailable', $row['portfolios'] ?? 'Protected / unavailable', $row['context']];
        }
        foreach ($report['data']['insights'] as $insight) {
            yield ['Rule-based insight', $insight];
        }
        foreach ($report['data']['quality'] as $finding) {
            yield ['Data quality', $finding['label'], $finding['count'] ?? 'Protected / unavailable', $finding['action']];
        }
    }

    public function purge(): int
    {
        $count = 0;
        ReportRun::where('expires_at', '<=', now())->chunkById(100, function ($runs) use (&$count): void {
            foreach ($runs as $run) {
                if ($run->path) {
                    Storage::disk('local')->delete($run->path);
                }$run->delete();
                $count++;
            }
        });

        return $count;
    }

    public function next(string $frequency, ?CarbonImmutable $from = null): CarbonImmutable
    {
        $now = ($from ?? CarbonImmutable::now())->timezone(config('intelligence.timezone'));

        return match ($frequency) {
            'weekly' => $now->addWeek()->startOfWeek()->setTime(8, 0)->utc(),'monthly' => $now->addMonthNoOverflow()->startOfMonth()->setTime(8, 0)->utc(),default => $now->addQuarter()->startOfQuarter()->setTime(8, 0)->utc()
        };
    }

    public function dispatchDue(): int
    {
        $count = 0;
        ReportSchedule::where('enabled', true)->where('next_run_at', '<=', now())->chunkById(100, function ($schedules) use (&$count): void {
            foreach ($schedules as $schedule) {
                DB::transaction(function () use ($schedule, &$count): void {
                    $s = ReportSchedule::whereKey($schedule->id)->lockForUpdate()->firstOrFail();
                    if (! $s->enabled || $s->next_run_at->isFuture()) {
                        return;
                    }
                    try {
                        $user = User::whereKey($s->user_id)->where('status', 'active')->firstOrFail();
                        $filters = $s->filters;
                        $local = CarbonImmutable::parse($s->next_run_at)->timezone(config('intelligence.timezone'));
                        $to = $local->subDay()->endOfDay();
                        $from = match ($s->frequency) {
                            'weekly' => $to->subDays(6)->startOfDay(),'monthly' => $to->startOfMonth(),default => $to->startOfQuarter()
                        };
                        $filters = array_replace($filters, ['from' => $from->toDateString(), 'to' => $to->toDateString()]);
                        $ctx = app(IntelligenceFilters::class)->normalise($user, $filters);
                        app(IntelligenceAccess::class)->context($user, $ctx, 'reports.schedule');
                        $run = $this->request($user, $ctx, $s->report_type, $s->format, $s->recipient_ids, $s, $s->next_run_at->toDateTimeString());
                        GenerateIntelligenceReport::dispatch($run->id)->onQueue('intelligence-reports')->afterCommit();
                        $count++;
                        $s->update(['next_run_at' => $this->next($s->frequency), 'last_status' => 'queued']);
                    } catch (Throwable $error) {
                        $s->update(['last_status' => 'blocked_authorisation_or_configuration', 'enabled' => false]);
                    }
                });
            }
        });

        return $count;
    }
}

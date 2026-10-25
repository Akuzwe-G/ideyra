<?php

namespace App\Support;

use App\Models\Institution;
use App\Models\InstitutionKpi;
use App\Models\KpiChange;
use App\Models\ReportingSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class KpiWorkflow
{
    public function save(User $user, Institution $institution, array $data, ?InstitutionKpi $kpi = null): InstitutionKpi
    {
        abort_unless(IntelligenceAccess::kpi($user, $institution->id, $kpi ? 'kpis.edit' : 'kpis.create'), 403);
        if ($kpi) {
            abort_unless($kpi->institution_id === $institution->id, 404);
        }
        abort_unless(InstitutionAccess::membership($institution, User::findOrFail($data['owner_id'])), 422, 'Select an active owner from this institution.');
        abort_if($data['direction'] === 'range' && (! isset($data['target_upper']) || $data['target_upper'] === null), 422, 'Range targets require an upper bound.');
        abort_unless(($data['source'] === 'manual') === ($data['metric'] === 'manual'), 422, 'Choose a registry metric for automatic calculation, or the manual metric for manual reporting.');
        $filters = app(IntelligenceFilters::class)->normalise($user, array_filter(collect($data)->only(['programme_id', 'academic_year_id', 'intake_id', 'academic_class_id'])->all(), fn ($v) => $v !== null && $v !== '') + ['institution_id' => $institution->id, 'from' => $data['period_from'], 'to' => $data['period_to'], 'compare' => false]);
        $values = collect($data)->only(['title', 'description', 'category', 'source', 'metric', 'period_from', 'period_to', 'target', 'target_upper', 'direction', 'owner_id', 'notes'])->all();
        $values['unit'] = $data['source'] === 'automatic' ? config('intelligence.metrics')[$data['metric']][1] : $data['unit'];
        $values['filters'] = $filters;

        return DB::transaction(function () use ($user, $institution, $data, $kpi, $values): InstitutionKpi {
            if (! $kpi) {
                $kpi = InstitutionKpi::create($values + ['institution_id' => $institution->id, 'created_by' => $user->id, 'lifecycle' => 'draft']);
                Audit::record('kpi.created', $kpi, $institution->id, ['metric' => $kpi->metric], $user->id);

                return $kpi;
            }
            $kpi = InstitutionKpi::whereKey($kpi->id)->lockForUpdate()->firstOrFail();
            abort_unless($kpi->version === (int) $data['version'], 409, 'This KPI changed. Reload before editing.');
            abort_if($kpi->lifecycle === 'closed', 422, 'Closed KPI definitions are immutable.');
            abort_if($kpi->changes()->where('status', 'pending')->exists(), 422, 'Decide the pending change before proposing another.');
            $sensitive = $kpi->lifecycle === 'active';
            if ($sensitive) {
                abort_if($kpi->period_to->lt(today(config('intelligence.timezone'))), 422, 'Targets for ended reporting periods cannot be rewritten.');
                $this->propose($kpi, $user, $values, $data['reason']);

                return $kpi;
            }
            $previous = $kpi->only(array_keys($values));
            $kpi->fill($values);
            $kpi->version++;
            $kpi->save();
            $kpi->changes()->create(['requested_by' => $user->id, 'status' => 'applied', 'previous' => $previous, 'proposed' => $values, 'reason' => $data['reason'], 'base_version' => $kpi->version - 1, 'decided_at' => now()]);
            Audit::record('kpi.draft_updated', $kpi, $institution->id, ['version' => $kpi->version], $user->id);

            return $kpi;
        });
    }

    private function propose(InstitutionKpi $kpi, User $user, array $values, string $reason): void
    {
        $required = ReportingSetting::where('institution_id', $kpi->institution_id)->first()?->require_kpi_approval ?? true;
        $inProgress = $kpi->lifecycle === 'active' && $kpi->period_from->lte(today(config('intelligence.timezone')));
        $pending = $required || $inProgress;
        $previousTarget = $kpi->target;
        $kpi->changes()->create(['requested_by' => $user->id, 'status' => $pending ? 'pending' : 'applied', 'previous' => $kpi->only(array_keys($values)), 'proposed' => $values, 'reason' => $reason, 'base_version' => $kpi->version, 'decided_at' => $pending ? null : now()]);
        if (! $pending) {
            $kpi->fill($values);
            $kpi->version++;
            $kpi->save();
        }
        Audit::record($pending ? 'kpi.change_requested' : 'kpi.change_applied', $kpi, $kpi->institution_id, ['version' => $kpi->version, 'previous_target' => $previousTarget, 'new_target' => $values['target'] ?? $kpi->target], $user->id);
    }

    public function action(User $user, InstitutionKpi $kpi, string $action, string $reason): void
    {
        Gate::forUser($user)->authorize('update', $kpi);
        DB::transaction(function () use ($user, $kpi, $action, $reason): void {
            $kpi = InstitutionKpi::whereKey($kpi->id)->lockForUpdate()->firstOrFail();
            abort_if($kpi->changes()->where('status', 'pending')->exists(), 422, 'There is already a pending change.');
            if ($action === 'activate') {
                abort_unless($kpi->lifecycle === 'draft', 422);
                abort_if($kpi->period_to->lt(today(config('intelligence.timezone'))), 422, 'An ended-period KPI cannot be activated retrospectively.');
                $this->propose($kpi, $user, ['lifecycle' => 'active'], $reason);
            } elseif ($action === 'close') {
                abort_unless($kpi->lifecycle === 'active', 422);
                $kpi->update(['lifecycle' => 'closed', 'version' => $kpi->version + 1]);
                Audit::record('kpi.closed', $kpi, $kpi->institution_id, [], $user->id);
            } else {
                abort(422);
            }
        });
    }

    public function decide(User $user, KpiChange $change, bool $approve, string $reason): void
    {
        $kpi = $change->kpi;
        Gate::forUser($user)->authorize('approve', $kpi);
        abort_if($change->requested_by === $user->id, 403, 'A different authorised approver must review this request.');
        DB::transaction(function () use ($user, $change, $approve, $reason): void {
            $change = KpiChange::whereKey($change->id)->lockForUpdate()->firstOrFail();
            $kpi = InstitutionKpi::whereKey($change->institution_kpi_id)->lockForUpdate()->firstOrFail();
            abort_unless($change->status === 'pending' && $change->base_version === $kpi->version, 409, 'This request is stale or already decided.');
            if ($approve) {
                abort_if($kpi->lifecycle === 'closed', 422);
                abort_if(($change->proposed['lifecycle'] ?? null) === 'active' && $kpi->period_to->lt(today(config('intelligence.timezone'))), 422, 'Ended-period activation cannot be approved.');
                if (isset($change->proposed['target'])) {
                    abort_if($kpi->period_to->lt(today(config('intelligence.timezone'))), 422, 'Ended-period targets cannot be changed.');
                }
                $kpi->fill($change->proposed);
                $kpi->version++;
                $kpi->save();
            }
            $change->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $user->id, 'decision_reason' => $reason, 'decided_at' => now()]);
            Audit::record($approve ? 'kpi.change_approved' : 'kpi.change_rejected', $kpi, $kpi->institution_id, ['change_id' => $change->id], $user->id);
        });
    }

    public function observe(User $user, InstitutionKpi $kpi, array $data, ?UploadedFile $file = null): void
    {
        Gate::forUser($user)->authorize('update', $kpi);
        abort_unless($kpi->source === 'manual' && $kpi->lifecycle === 'active', 422, 'Only active, manually reported KPIs accept observations.');
        abort_unless($data['observed_on'] >= $kpi->period_from->toDateString() && $data['observed_on'] <= $kpi->period_to->toDateString() && $data['observed_on'] <= today(config('intelligence.timezone'))->toDateString(), 422, 'Choose an observation date inside the KPI period and not in the future.');
        $path = $file?->store('reporting/kpi-evidence', 'local');
        try {
            $observation = $kpi->observations()->create(collect($data)->only(['value', 'observed_on', 'evidence'])->all() + ['reported_by' => $user->id, 'evidence_path' => $path]);
            Audit::record('kpi.observation_recorded', $kpi, $kpi->institution_id, ['observation_id' => $observation->id], $user->id);
        } catch (\Throwable $error) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }throw $error;
        }
    }

    public function actual(User $user, InstitutionKpi $kpi, ?array $overrideFilters = null): ?float
    {
        if ($kpi->source === 'manual') {
            if ($overrideFilters === null && $kpi->relationLoaded('observations')) {
                return $kpi->observations->first()?->value;
            }
            $q = $kpi->observations();
            if ($overrideFilters) {
                $q->whereBetween('observed_on', [$overrideFilters['from'], $overrideFilters['to']]);
            }

            return $q->value('value');
        }
        $filters = app(IntelligenceFilters::class)->normalise($user, $overrideFilters ?? $kpi->filters);
        $context = app(IntelligenceAccess::class)->context($user, $filters);
        $data = app(IntelligenceMetrics::class)->cached($context, $filters);

        return $data['metrics'][$kpi->metric]['value'] === null ? null : (float) $data['metrics'][$kpi->metric]['value'];
    }

    public function performance(?float $actual, float $target, ?float $upper, string $direction, bool $ended = false): array
    {
        if ($actual === null) {
            return ['actual' => null, 'achievement' => null, 'performance' => 'insufficient_data'];
        }
        $achieved = match ($direction) {
            'decrease' => $actual <= $target,'range' => $actual >= $target && $actual <= $upper,default => $actual >= $target
        };
        $achievement = match ($direction) {
            'decrease' => $actual === 0.0 ? 100.0 : ($target === 0.0 ? 0.0 : $target / $actual * 100),
            'range' => $achieved ? 100.0 : ($actual < $target ? ($target > 0 ? $actual / $target * 100 : 0) : ($actual > 0 ? $upper / $actual * 100 : 0)),
            default => $target === 0.0 ? 100.0 : $actual / $target * 100,
        };

        return ['actual' => $actual, 'achievement' => round(max(0, $achievement), 2), 'performance' => $achieved ? 'achieved' : (! $ended && $achievement >= 80 ? 'on_track' : 'at_risk')];
    }

    public function score(User $user, InstitutionKpi $kpi): array
    {
        $actual = $this->actual($user, $kpi);
        $result = $this->performance($actual, $kpi->target, $kpi->target_upper, $kpi->direction, $kpi->period_to->lt(today(config('intelligence.timezone'))));
        $previous = $kpi->source === 'automatic' ? $this->actual($user, $kpi, app(IntelligenceFilters::class)->previous($kpi->filters)) : $kpi->observations()->skip(1)->value('value');

        return $result + ['previous' => $previous, 'trend' => $actual !== null && $previous !== null ? round($actual - $previous, 2) : null];
    }
}

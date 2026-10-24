<?php

namespace App\Support;

use App\Models\AchievementVerification;
use App\Models\CvDocument;
use App\Models\Institution;
use App\Models\Portfolio;
use App\Models\ProfessionalEntry;
use App\Models\ProfessionalProfile;
use App\Models\ProjectShowcase;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CareerAnalytics
{
    public function data(ProfessionalProfile $profile, array $filters, ?Institution $institution = null): array
    {
        if ($institution) {
            abort_unless(CareerAccess::report($institution, auth()->user()), 403);
            $users = $institution->memberships()->where('status', 'active')->whereHas('user', fn ($q) => $q->where('status', 'active'))->whereHas('role', fn ($q) => $q->where('name', 'Student'))->select('user_id');
            $profiles = ProfessionalProfile::whereIn('user_id', clone $users);
            $verifications = AchievementVerification::where('institution_id', $institution->id)->where('status', 'verified')->whereIn('user_id', clone $users);
            $portfolios = Portfolio::whereIn('professional_profile_id', (clone $profiles)->select('id'));
            $showcases = ProjectShowcase::whereIn('professional_profile_id', (clone $profiles)->select('id'))->whereHas('project', fn ($q) => $q->where('institution_id', $institution->id));
            $charts = [
                $this->timeline('profile-adoption', 'Profile adoption over time', (clone $profiles), $filters, 'created_at', 'Profiles created by currently active student members.'),
                $this->groups('verified-categories', 'Verified achievements by category', 'bar', $this->window(clone $verifications, $filters, 'verified_at'), 'category', 'Current verified decisions dated in this range for this institution.'),
                $this->publicationTimeline($portfolios, $filters),
                $this->programmeChart($showcases, $institution, $filters),
            ];
            $kpis = ['Student profiles' => (clone $profiles)->count(), 'Published portfolios' => (clone $portfolios)->where('published', true)->count(),
                'Verified institutional records' => (clone $verifications)->count(), 'Institution-linked showcases' => (clone $showcases)->count(),
                'Students with CVs' => CvDocument::whereIn('user_id', clone $users)->distinct()->count('user_id')];
        } else {
            $entries = $profile->entries();
            $charts = [
                $this->groups('skill-category', 'Skills by category', 'doughnut', $this->window(ProfessionalEntry::where('professional_profile_id', $profile->id)->where('type', 'skill'), $filters), 'category', 'Recorded skills created in this range. No proficiency or employability score.'),
                $this->groups('career-project-status', 'Projects by status', 'bar', $this->window(InnovationAccess::projects(auth()->user()), $filters), 'status', 'Accessible projects created in this range. Visibility does not imply contribution or public sharing permission.'),
                $this->timeline('achievement-timeline', 'Achievement timeline', ProfessionalEntry::where('professional_profile_id', $profile->id)->where('type', 'achievement'), $filters, 'created_at', 'When achievements were recorded, including unverified self-reports.'),
            ];
            $kpis = ['Profile completeness' => app(CareerWorkflow::class)->completeness($profile)['percent'], 'Recorded skills' => (clone $entries)->where('type', 'skill')->count(),
                'Accessible showcases' => $profile->showcases()->get()->filter(fn ($s) => CareerAccess::projectContent($s) !== null)->count(),
                'Verified records' => AchievementVerification::where('user_id', $profile->user_id)->where('status', 'verified')->count(),
                'Private CV versions' => CvDocument::where('user_id', $profile->user_id)->count(), 'Portfolio published' => (int) ($profile->portfolio?->published ?? false)];
        }

        return ['kpis' => $kpis, 'charts' => $charts];
    }

    private function window(Builder $query, array $filters, string $column = 'created_at'): Builder
    {
        return $query->where($column, '>=', $filters['from'].' 00:00:00')->where($column, '<', CarbonImmutable::parse($filters['to'])->addDay()->toDateTimeString());
    }

    private function groups(string $key, string $title, string $type, Builder $query, string $column, string $note): array
    {
        $rows = $query->select($column)->selectRaw('COUNT(*) as total')->groupBy($column)->orderBy($column)->get();

        return $this->chart($key, $title, $type, $rows->map(fn ($r) => ucwords(str_replace('_', ' ', $r->$column ?: 'Other')))->all(), $rows->pluck('total')->map(fn ($n) => (int) $n)->all(), $note);
    }

    private function timeline(string $key, string $title, Builder $query, array $filters, string $column, string $note): array
    {
        $rows = $this->window($query, $filters, $column)->selectRaw('DATE('.$column.') as day, COUNT(*) as total')->groupByRaw('DATE('.$column.')')->get();

        return $this->timelineRows($key, $title, $rows, $filters, $note);
    }

    private function timelineRows(string $key, string $title, Collection $rows, array $filters, string $note): array
    {
        $bucket = fn ($date) => match ($filters['group']) {
            'daily' => $date->startOfDay(),'weekly' => $date->startOfWeek(),default => $date->startOfMonth()
        };
        $values = [];
        for ($date = $bucket(CarbonImmutable::parse($filters['from'])); $date->lte(CarbonImmutable::parse($filters['to'])); $date = match ($filters['group']) {
            'daily' => $date->addDay(),'weekly' => $date->addWeek(),default => $date->addMonth()
        }) {
            $values[$date->toDateString()] = 0;
        }
        foreach ($rows as $row) {
            $values[$bucket(CarbonImmutable::parse($row->day))->toDateString()] += (int) $row->total;
        }

        return $this->chart($key, $title, 'line', array_keys($values), array_values($values), $note);
    }

    private function publicationTimeline(Builder $portfolios, array $filters): array
    {
        $rows = DB::table('career_publication_events')->whereIn('portfolio_id', $portfolios->select('id'))->where('published', true)->where('created_at', '>=', $filters['from'].' 00:00:00')->where('created_at', '<', CarbonImmutable::parse($filters['to'])->addDay()->toDateTimeString())->selectRaw('DATE(created_at) as day, COUNT(*) as total')->groupByRaw('DATE(created_at)')->get();

        return $this->timelineRows('portfolio-publications', 'Portfolio publication trends', $rows, $filters, 'Explicit transitions from unpublished to published among current active students. Republishing after unpublishing counts as another event; not unique students or visitor analytics.');
    }

    private function programmeChart(Builder $showcases, Institution $institution, array $filters): array
    {
        $ids = $this->window($showcases, $filters)->select('project_showcases.id');
        $rows = DB::table('project_showcases as s')->join('professional_profiles as p', 'p.id', '=', 's.professional_profile_id')->join('memberships as m', 'm.user_id', '=', 'p.user_id')
            ->join('class_assignments as a', 'a.membership_id', '=', 'm.id')->join('academic_classes as c', 'c.id', '=', 'a.academic_class_id')->join('programmes as pg', 'pg.id', '=', 'c.programme_id')
            ->where('m.institution_id', $institution->id)->where('m.status', 'active')->whereIn('s.id', $ids)->select('pg.id', 'pg.name')->selectRaw('COUNT(DISTINCT p.user_id) as total')->groupBy('pg.id', 'pg.name')->get();

        return $this->chart('showcase-programmes', 'Showcase participation by programme', 'bar', $rows->pluck('name')->all(), $rows->pluck('total')->map(fn ($n) => (int) $n)->all(), 'Unique current students with institution-linked showcases created in this period. Multiple classes in one programme deduplicate; students in multiple programmes appear in each.');
    }

    private function chart(string $key, string $title, string $type, array $labels, array $values, string $note): array
    {
        return compact('key', 'title', 'type', 'labels', 'values', 'note') + ['unit' => 'Records'];
    }
}

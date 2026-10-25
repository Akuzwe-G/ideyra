<?php

namespace App\Support;

use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\OpportunityApplication;
use App\Models\SponsorshipRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class OpportunityAnalytics
{
    public function personalSummary(User $user): array
    {
        $memberships = InnovationAccess::memberships($user);
        $visible = OpportunityAccess::visible($user, false);
        $saved = (clone $visible)->whereIn('id', DB::table('opportunity_bookmarks')->where('user_id', $user->id)->select('opportunity_id'));
        $eligible = (clone $visible)->where('status', 'published')->orderByDesc('id')->cursor()->filter(fn ($o) => $memberships->contains(fn ($m) => OpportunityAccess::eligible($o, $user, $m->institution_id)))->take(3)->collect();
        $applications = OpportunityAccess::applications($user)->where('applicant_id', $user->id);

        return ['eligible' => $eligible, 'deadlines' => (clone $saved)->where('status', 'published')->whereBetween('deadline_at', [now(), now()->addDays(7)])->orderBy('deadline_at')->limit(4)->get(), 'counts' => ['Saved opportunities' => (clone $saved)->count(), 'My opportunity applications' => (clone $applications)->count(), 'Accepted outcomes' => (clone $applications)->where('status', 'accepted')->count(), 'Accessible support requests' => OpportunityAccess::sponsorships($user)->count()]];
    }

    public static function institutionAllowed(Institution $institution, User $user): bool
    {
        return InnovationAccess::can($institution, 'opportunities.view', $user) && (InnovationAccess::can($institution, 'opportunities.manage_all', $user) || InnovationAccess::can($institution, 'opportunities.review_applications', $user));
    }

    public function data(array $filters, string $scope = 'personal', ?Institution $institution = null): array
    {
        $user = auth()->user();
        $catalog = Opportunity::query();
        $applications = OpportunityApplication::where('external_self_reported', false)->whereNotNull('submitted_at');
        $requests = SponsorshipRequest::query();
        if (! $user) {
            $catalog->whereRaw('1=0');
            $applications->whereRaw('1=0');
            $requests->whereRaw('1=0');
        } elseif ($scope === 'system') {
            abort_unless(InstitutionAccess::system($user), 403);
            $requests->whereRaw('1=0');
        } elseif ($scope === 'institution') {
            abort_unless($institution && self::institutionAllowed($institution, $user), 403);
            $catalog->where(fn ($q) => $q->where('institution_id', $institution->id)->orWhere(fn ($platform) => $platform->whereNull('institution_id')->where('visibility', 'platform')->whereIn('status', ['published', 'closed'])));
            $applications->where('institution_id', $institution->id);
            $requests->whereIn('id', OpportunityAccess::sponsorships($user)->whereIn('project_id', DB::table('projects')->where('institution_id', $institution->id)->select('id'))->select('id'));
        } else {
            $catalog->whereIn('opportunities.id', OpportunityAccess::visible($user, false)->select('opportunities.id'));
            $applications->whereIn('id', OpportunityAccess::applications($user)->where(fn ($q) => $q->where('applicant_id', $user->id)->orWhereExists(fn ($c) => $c->selectRaw('1')->from('opportunity_consents')->whereColumn('application_id', 'opportunity_applications.id')->where('user_id', $user->id)))->select('opportunity_applications.id'));
            $requests->whereIn('id', OpportunityAccess::sponsorships($user)->select('id'));
        }
        $opportunities = $this->window(clone $catalog, $filters, 'opportunities.created_at');
        $apps = $this->window(clone $applications, $filters, 'submitted_at');
        $statuses = ['submitted', 'under_review', 'more_information_requested', 'shortlisted', 'accepted', 'rejected', 'withdrawn'];
        $counts = (clone $apps)->select('status')->selectRaw('COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $available = $scope === 'institution' ? $this->window($this->availableToMembers($institution), $filters, 'opportunities.created_at') : null;
        $categories = ($available ? clone $available : clone $opportunities)->join('opportunity_categories as category', 'category.id', '=', 'opportunities.category_id')->select('category.name')->selectRaw('COUNT(*) as total')->groupBy('category.id', 'category.name')->orderByDesc('total')->get();
        $charts = [];
        [$dates,$published] = $this->timeline($this->window(clone $catalog, $filters, 'published_at')->whereNotNull('published_at'), $filters, 'published_at');
        [$appDates,$appCounts] = $this->timeline(clone $apps, $filters, 'submitted_at');
        $charts[] = $this->chart('opportunity-publication', 'Opportunity publication trend', 'line', $dates, $published, 'Most recent publication timestamp per listing in the selected range. Republishing does not create a second listing.', 'Opportunities');
        $charts[] = $this->chart('opportunity-categories', 'Opportunities by category', $scope === 'institution' ? 'doughnut' : 'bar', $categories->pluck('name')->all(), $categories->pluck('total')->map(fn ($n) => (int) $n)->all(), $scope === 'institution' ? 'Published or closed listings created in this period with an audience currently available to institution members. Drafts are excluded; individual application eligibility is separate.' : 'Current categories of catalogue records created in the selected period within the authorised reporting scope.', 'Opportunities');
        $charts[] = $this->chart('opportunity-application-status', 'Opportunity application status', 'doughnut', array_map(fn ($s) => ucwords(str_replace('_', ' ', $s)), $statuses), array_map(fn ($s) => (int) ($counts[$s] ?? 0), $statuses), 'Internal applications submitted in the selected period. One individual or group application is one record; external self-reports and drafts are excluded.', 'Applications');
        $charts[] = $this->chart('opportunity-applications', 'Opportunity applications over time', 'line', $appDates, $appCounts, 'Internal submissions by current latest submission date, including resubmitted applications once. Not participant headcount.', 'Applications');
        if ($scope === 'institution') {
            $charts = array_values(array_filter($charts, fn ($chart) => $chart['key'] === 'opportunity-categories'));
        }
        $participants = DB::table('application_participants as p')->whereIn('p.application_id', (clone $apps)->select('opportunity_applications.id'));
        $uniqueStudents = (clone $participants)->distinct()->count('p.user_id');
        $institutionCount = (clone $apps)->distinct()->count('institution_id');
        $publishedCount = (clone $opportunities)->where('status', 'published')->count();
        $openCount = (clone $opportunities)->where('status', 'published')->where(fn ($q) => $q->whereNull('opens_at')->orWhere('opens_at', '<=', now()))->where(fn ($q) => $q->whereNull('deadline_at')->orWhere('deadline_at', '>=', now()))->count();
        $kpis = ['Catalogue opportunities' => (clone $opportunities)->count(), 'Published opportunities' => $publishedCount, 'Open opportunities' => $openCount, 'Internal applications' => (clone $apps)->count(), 'Awaiting review' => array_sum($counts->only(['submitted', 'under_review', 'more_information_requested'])->all()), 'Shortlisted applications' => (int) ($counts['shortlisted'] ?? 0), 'Accepted applications' => (int) ($counts['accepted'] ?? 0), 'Unique student participants' => $uniqueStudents, 'Participating institutions' => $institutionCount];
        if ($scope === 'institution') {
            $programmes = (clone $participants)->leftJoin('programmes as programme', 'programme.id', '=', 'p.programme_id')->selectRaw("COALESCE(programme.name,'No programme recorded') as label, COUNT(DISTINCT p.application_id) as total")->groupBy('programme.id', 'programme.name')->orderByDesc('total')->get();
            $charts[] = $this->chart('opportunity-programme-applications', 'Applications by programme', 'bar', $programmes->pluck('label')->all(), $programmes->pluck('total')->map(fn ($n) => (int) $n)->all(), 'Distinct submitted applications per recorded primary programme. A group application counts once, irrespective of member count. Unique students are reported separately.', 'Applications');
            $rows = (clone $participants)->join('opportunity_applications as a', 'a.id', '=', 'p.application_id')->selectRaw('DATE(a.submitted_at) as day,p.user_id')->distinct()->get();
            [$periods,$studentCounts] = $this->timeline($rows, $filters, 'day', true);
            $charts[] = $this->chart('opportunity-student-participation', 'Student participation over time', 'line', $periods, $studentCounts, 'Unique participating students per time bucket, deduplicated across individual and group applications. A student can appear in different periods.', 'Students');
            $requestCounts = $this->window(clone $requests, $filters, 'created_at')->select('status')->selectRaw('COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
            $charts[] = $this->chart('opportunity-support-status', 'Sponsorship request status', 'bar', array_map(fn ($s) => ucwords(str_replace('_', ' ', $s)), array_keys($requestCounts->all())), array_map('intval', array_values($requestCounts->all())), 'Authorised project support requests created in this period. Values are counts, never payments or money received.', 'Requests');
            $kpis['Institution-owned opportunities'] = (clone $opportunities)->where('opportunities.institution_id', $institution->id)->count();
            $kpis['Available to institution members'] = $available->count();
            $kpis['Sponsorship requests'] = array_sum($requestCounts->all());
        }
        if ($scope === 'personal' && $user) {
            $kpis['Saved opportunities'] = DB::table('opportunity_bookmarks')->where('user_id', $user->id)->whereIn('opportunity_id', (clone $catalog)->select('opportunities.id'))->count();
            $kpis['Accessible sponsorship requests'] = (clone $requests)->count();
            $kpis['My applications including drafts'] = OpportunityAccess::applications($user)->where('applicant_id', $user->id)->count();
        }
        $decided = (int) ($counts['accepted'] ?? 0) + (int) ($counts['rejected'] ?? 0);
        $activeIds = $institution ? DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->join('roles as r', 'r.id', '=', 'm.role_id')->where('m.institution_id', $institution->id)->where('m.status', 'active')->where('u.status', 'active')->where('r.name', 'Student')->select('u.id') : null;
        $activeStudents = $activeIds ? (clone $activeIds)->distinct()->count('u.id') : 0;
        $activeParticipants = $activeIds ? (clone $participants)->whereIn('p.user_id', $activeIds)->distinct()->count('p.user_id') : 0;

        return ['charts' => $charts, 'kpis' => $kpis, 'rates' => ['accepted' => (int) ($counts['accepted'] ?? 0), 'decided' => $decided, 'unique_participants' => $uniqueStudents, 'active_students' => $activeStudents, 'active_participants' => $activeParticipants], 'hasData' => (clone $catalog)->exists() || (clone $applications)->exists() || (clone $requests)->exists(), 'recentApplications' => collect(), 'recentInstitutions' => collect(), 'activities' => collect()];
    }

    public function availableToMembers(Institution $institution, ?\Illuminate\Database\Query\Builder $studentIds = null): Builder
    {
        $members = DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->join('roles as r', 'r.id', '=', 'm.role_id')->where('m.institution_id', $institution->id)->where('m.status', 'active')->where('u.status', 'active')
            ->whereNotExists(fn ($deny) => $deny->selectRaw('1')->from('membership_permissions as override')->join('permissions as permission', 'permission.id', '=', 'override.permission_id')->whereColumn('override.membership_id', 'm.id')->where('permission.name', 'opportunities.view')->where('override.allowed', false))
            ->where(fn ($grants) => $grants->whereJsonContains('r.permissions', 'opportunities.view')->orWhereExists(fn ($allow) => $allow->selectRaw('1')->from('membership_permissions as override')->join('permissions as permission', 'permission.id', '=', 'override.permission_id')->whereColumn('override.membership_id', 'm.id')->where('permission.name', 'opportunities.view')->where('override.allowed', true)));
        if ($studentIds) {
            $members->whereIn('m.user_id', $studentIds);
        }
        $classes = DB::table('class_assignments as a')->join('academic_classes as c', 'c.id', '=', 'a.academic_class_id')->whereIn('a.membership_id', (clone $members)->select('m.id'));
        $targets = ['role' => (clone $members)->select('m.role_id'), 'class' => (clone $classes)->select('c.id'), 'programme' => (clone $classes)->select('c.programme_id'), 'academic_year' => (clone $classes)->select('c.academic_year_id'), 'intake' => (clone $classes)->select('c.intake_id'), 'group' => DB::table('group_members')->whereIn('membership_id', (clone $members)->select('m.id'))->select('group_id')];

        return Opportunity::whereIn('opportunities.status', ['published', 'closed'])->where(function ($scope) use ($institution, $targets): void {
            $scope->where(fn ($platform) => $platform->whereNull('opportunities.institution_id')->where('visibility', 'platform'))->orWhere(function ($own) use ($institution, $targets): void {
                $own->where('opportunities.institution_id', $institution->id)->where(fn ($visible) => $visible->where('visibility', 'institution')->orWhere(fn ($restricted) => $restricted->where('visibility', 'restricted')->whereExists(function ($audience) use ($targets): void {
                    $audience->selectRaw('1')->from('opportunity_audiences as audience')->whereColumn('audience.opportunity_id', 'opportunities.id')->where(function ($match) use ($targets): void {
                        $match->whereRaw('1=0');
                        foreach ($targets as $kind => $query) {
                            $match->orWhere(fn ($target) => $target->where('audience.kind', $kind)->whereIn('audience.reference_id', $query));
                        }
                    });
                })));
            });
        });
    }

    private function window(mixed $query, array $filters, string $column): mixed
    {
        return $query->where($column, '>=', $filters['from'].' 00:00:00')->where($column, '<', CarbonImmutable::parse($filters['to'])->addDay()->toDateTimeString());
    }

    private function timeline(mixed $query, array $filters, string $column, bool $uniqueUsers = false): array
    {
        $bucket = fn (CarbonImmutable $date) => match ($filters['group']) {
            'daily' => $date->startOfDay(),'weekly' => $date->startOfWeek(),default => $date->startOfMonth()
        };
        $values = [];
        $seen = [];
        $end = $bucket(CarbonImmutable::parse($filters['to']));
        for ($cursor = $bucket(CarbonImmutable::parse($filters['from'])); $cursor->lte($end); $cursor = match ($filters['group']) {
            'daily' => $cursor->addDay(),'weekly' => $cursor->addWeek(),default => $cursor->addMonth()
        }) {
            $values[$cursor->toDateString()] = 0;
        }
        $rows = $uniqueUsers ? $query : $query->selectRaw('DATE('.$column.') as day,COUNT(*) as total')->groupByRaw('DATE('.$column.')')->get();
        foreach ($rows as $row) {
            $key = $bucket(CarbonImmutable::parse($row->day))->toDateString();
            if ($uniqueUsers) {
                $seen[$key][$row->user_id] = true;
            } else {
                $values[$key] += (int) $row->total;
            }
        }
        if ($uniqueUsers) {
            foreach ($seen as $key => $users) {
                $values[$key] = count($users);
            }
        }

        return [array_keys($values), array_values($values)];
    }

    private function chart(string $key, string $title, string $type, array $labels, array $values, string $note, string $unit): array
    {
        return compact('key', 'title', 'type', 'labels', 'values', 'note', 'unit');
    }
}

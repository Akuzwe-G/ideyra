<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class NetworkAnalytics
{
    public function data(array $context, array $filters, IntelligenceMetrics $metrics): array
    {
        $f = app(IntelligenceFilters::class);
        $iid = $context['institution_id'];
        $academic = $f->hasAcademic($context, $filters);
        $students = $metrics->students($context, $filters);
        $publications = DB::table('network_publications as np')->where('np.status', 'published')->whereIn('np.visibility', ['institution', 'network', 'public'])->whereColumn('np.version', 'np.approved_version')->when($iid, fn ($q, $id) => $q->where('np.institution_id', $id));
        if ($academic) {
            $publications->whereIn('np.project_id', $metrics->projects($context, $filters)->select('p.id'));
        }
        if (! empty($filters['project_category'])) {
            $publications->where('np.category', $filters['project_category']);
        }
        $publications = $f->window($publications, $filters, 'np.published_at');
        $requests = DB::table('collaboration_requests as cr')->join('network_publications as rp', 'rp.id', '=', 'cr.network_publication_id')->whereNotNull('cr.submitted_at')->when($iid, fn ($q, $id) => $q->where('rp.institution_id', $id));
        if ($academic) {
            $requests->whereIn('rp.project_id', $metrics->projects($context, $filters)->select('p.id'));
        }
        $requests = $f->window($requests, $filters, 'cr.submitted_at');
        $workspaces = DB::table('collaboration_workspaces as cw')->whereIn('cw.status', ['active', 'closed'])->when($iid, fn ($q, $id) => $q->whereJsonContains('cw.institution_ids', $id));
        if ($academic) {
            $workspaces->whereIn('cw.network_publication_id', DB::table('network_publications')->whereIn('project_id', $metrics->projects($context, $filters)->select('p.id'))->select('id'));
        }
        $workspaces = $f->window($workspaces, $filters, 'cw.created_at');
        $challenges = $f->window(DB::table('innovation_challenges as nc')->when($iid, fn ($q, $id) => $q->where('nc.institution_id', $id)), $filters, 'nc.created_at');
        $submissions = DB::table('challenge_submissions as ns')->join('innovation_challenges as sc', 'sc.id', '=', 'ns.innovation_challenge_id')->where('ns.status', 'submitted')->when($iid, fn ($q, $id) => $q->where('sc.institution_id', $id));
        if ($academic) {
            $submissions->whereIn('ns.project_id', $metrics->projects($context, $filters)->select('p.id'));
        }
        $submissions = $f->window($submissions, $filters, 'ns.submitted_at');
        $participation = DB::table('application_participants as cp')->join('opportunity_applications as ca', 'ca.id', '=', 'cp.application_id')->join('innovation_challenges as cc', 'cc.opportunity_id', '=', 'ca.opportunity_id')->where('ca.status', 'submitted')->when($iid, fn ($q, $id) => $q->where('cp.institution_id', $id));
        if ($iid || $academic) {
            $participation->whereIn('cp.user_id', clone $students);
        }
        $participation = $f->window($participation, $filters, 'ca.submitted_at');
        $events = $f->window(DB::table('innovation_events as ne')->when($iid, fn ($q, $id) => $q->where('ne.institution_id', $id)), $filters, 'ne.created_at');
        $attendees = DB::table('event_registrations as er')->whereIn('er.status', ['registered', 'attended'])->when($iid, fn ($q, $id) => $q->where('er.institution_id', $id));
        if ($academic) {
            $attendees->whereIn('er.user_id', clone $students);
        }
        $attendees = $f->window($attendees, $filters, 'er.registered_at');
        $recognitions = $f->window(DB::table('network_recognitions as nr')->where('nr.status', 'confirmed')->when($iid, fn ($q, $id) => $q->where('nr.institution_id', $id)), $filters, 'nr.confirmed_at');
        if ($academic) {
            $recognitions->whereIn('nr.user_id', clone $students);
        }
        $raw = ['network_publications' => (clone $publications)->count(), 'network_institutions' => (clone $publications)->distinct()->count('np.institution_id'), 'network_requests' => (clone $requests)->count(), 'network_active_collaborations' => (clone $workspaces)->where('cw.status', 'active')->count(), 'network_collaborations_initiated' => (clone $workspaces)->join('collaboration_requests as ir', 'ir.id', '=', 'cw.collaboration_request_id')->when($iid, fn ($q, $id) => $q->where('ir.institution_id', $id))->count(), 'network_challenges' => (clone $challenges)->count(), 'network_submissions' => (clone $submissions)->count(), 'network_challenge_participants' => (clone $participation)->distinct()->count('cp.user_id'), 'network_events' => (clone $events)->count(), 'network_event_participants' => (clone $attendees)->distinct()->count('er.user_id'), 'network_recognitions' => (clone $recognitions)->count()];
        $all = [];
        foreach ($raw as $key => $value) {
            [$label,$unit,$definition,$section] = config('intelligence.metrics')[$key];
            $all[$key] = ['key' => $key, 'label' => $label, 'unit' => $unit, 'definition' => $definition, 'section' => $section, 'value' => $metrics->protect($value, $unit === 'People')];
        }
        $byProgramme = (clone $participation)->leftJoin('programmes as prog', 'prog.id', '=', 'cp.programme_id');
        $charts = [
            $metrics->timeline('network-publications', 'Showcase publications over time', $publications, $filters, 'np.published_at', 'Approved, currently published institutional/network/public projections. Private and revoked publications are excluded.'),
            $metrics->groups('network-categories', 'Published innovations by category', $publications, 'np.category', 'Approved innovation categories, not internal private project descriptions.', 'doughnut', 'Publications'),
            $metrics->groups('network-requests', 'Collaboration requests by status', $requests, 'cr.status', 'Submitted requests received by the selected institution. Requests are not accepted collaborations.', 'bar', 'Requests'),
            $metrics->timeline('network-collaborations', 'Cross-institution collaborations over time', $workspaces, $filters, 'cw.created_at', 'Workspace creation after all required approvals. May now be active or closed; does not measure innovation impact.'),
            $metrics->groups('network-programmes', 'Challenge participants by programme', $byProgramme, 'prog.name', 'Distinct participating students per programme recorded at registration submission; people may appear in multiple programme categories. Small breakdowns are protected.', 'bar', 'People', true, 'cp.user_id'),
            $metrics->timeline('network-events', 'Event participation trend', $attendees, $filters, 'er.registered_at', 'Distinct currently registered/attended identities per local bucket. Cancellations are excluded; registration is not verified attendance.', 'er.user_id'),
        ];

        return ['metrics' => $all, 'selected' => $all, 'charts' => $charts, 'journey' => [], 'quality' => [], 'filters' => $filters, 'context' => $context, 'coverage' => ['eligible_students' => $metrics->protect((clone $students)->count('m.user_id'), true), 'project_cohort' => $raw['network_publications'], 'mature_ideas' => 0, 'timezone' => config('intelligence.timezone'), 'definitions' => config('intelligence.version')], 'insights' => ['Collaboration requests, approved shared workspaces, submitted entries and institutional recognitions are separate recorded events. None alone establishes innovation impact.'], 'comparison' => []];
    }
}

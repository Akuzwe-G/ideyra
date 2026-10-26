<?php

namespace App\Support;

use App\Models\EventRegistration;
use App\Models\ExhibitionProject;
use App\Models\InnovationChallenge;
use App\Models\InnovationEvent;
use App\Models\Institution;
use App\Models\NetworkPublication;
use App\Models\OpportunityApplication;
use App\Models\OpportunityCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class EventWorkflow
{
    public function save(User $user, array $data, ?InnovationEvent $event = null): InnovationEvent
    {
        $institution = Institution::findOrFail($data['institution_id']);
        abort_unless(NetworkAccess::can($user, $institution, $event ? 'events.manage' : 'events.create'), 403);
        abort_unless(NetworkAccess::publicScopeAllowed($institution, $data['visibility']), 422, 'Institution policy does not permit this event audience.');
        $starts = CarbonImmutable::parse($data['starts_at'], $data['timezone'])->utc();
        $ends = CarbonImmutable::parse($data['ends_at'], $data['timezone'])->utc();
        abort_unless($ends->gt($starts), 422);
        abort_unless(! empty($data['venue']) || ! empty($data['online_url']), 422, 'Provide a venue or an authorised online location.');
        $agenda = [];
        foreach ($data['agenda'] ?? [] as $slot) {
            $a = CarbonImmutable::parse($slot['starts_at'], $data['timezone'])->utc();
            $b = CarbonImmutable::parse($slot['ends_at'], $data['timezone'])->utc();
            abort_unless($a->gte($starts) && $b->lte($ends) && $b->gt($a), 422, 'Agenda slots must fit inside the event.');
            $agenda[] = ['title' => $slot['title'], 'starts_at' => $a->toIso8601String(), 'ends_at' => $b->toIso8601String()];
        }

        return DB::transaction(function () use ($user, $institution, $data, $event, $starts, $ends, $agenda): InnovationEvent {
            $challenge = empty($data['innovation_challenge_id']) ? null : InnovationChallenge::findOrFail($data['innovation_challenge_id']);
            if ($challenge) {
                abort_unless($challenge->institution_id === $institution->id && NetworkAccess::challengeManage($user, $challenge), 403);
                abort_unless($starts->gte($challenge->opportunity->deadline_at), 422, 'Linked challenge exhibitions begin after the submission deadline.');
            }
            if ($event) {
                Gate::forUser($user)->authorize('update', $event);
                $event = InnovationEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
                abort_unless($event->institution_id === $institution->id && in_array($event->status, ['draft', 'returned'], true) && (int) $event->innovation_challenge_id === (int) $challenge?->id, 422);
                $opportunity = $event->opportunity;
            } elseif ($challenge) {
                abort_if(InnovationEvent::where('innovation_challenge_id', $challenge->id)->exists(), 422, 'This challenge already has an event. Open that shared event instead.');
                $opportunity = $challenge->opportunity;
                $event = new InnovationEvent(['opportunity_id' => $opportunity->id, 'innovation_challenge_id' => $challenge->id, 'institution_id' => $institution->id, 'created_by' => $user->id]);
            } else {
                $opportunity = app(OpportunityWorkflow::class)->save(['institution_id' => $institution->id, 'title' => $data['title'], 'summary' => mb_substr($data['description'], 0, 600), 'description' => $data['description'], 'organiser' => $data['organiser'], 'category_id' => OpportunityCategory::where('slug', 'events-exhibitions')->sole()->id, 'source_attribution' => 'Institution organiser-provided event', 'application_method' => 'internal', 'instructions' => $data['registration_rules'], 'eligibility_text' => $data['registration_rules'], 'delivery_mode' => empty($data['online_url']) ? 'in_person' : 'online', 'venue' => $data['venue'] ?? null, 'opens_at' => now(), 'deadline_at' => $starts, 'event_starts_at' => $starts->toDateString(), 'event_ends_at' => $ends->toDateString(), 'visibility' => 'institution', 'allow_groups' => false]);
                $opportunity->update(['network_type' => 'event']);
                $event = new InnovationEvent(['opportunity_id' => $opportunity->id, 'institution_id' => $institution->id, 'created_by' => $user->id]);
            }
            $event->fill(collect($data)->only(['title', 'description', 'organiser', 'kind', 'venue', 'online_url', 'timezone', 'capacity', 'registration_rules', 'visibility'])->all());
            $event->fill(['starts_at' => $starts, 'ends_at' => $ends, 'agenda' => $agenda, 'status' => 'draft', 'approved_by' => null])->save();
            if (! $challenge) {
                $opportunity->fill(['title' => $data['title'], 'summary' => mb_substr($data['description'], 0, 600), 'description' => $data['description'], 'organiser' => $data['organiser'], 'deadline_at' => $starts, 'status' => 'draft', 'approved_by' => null])->save();
            }
            NetworkSignals::record('event.draft_saved', $event, $institution->id);

            return $event;
        });
    }

    public function transition(User $user, InnovationEvent $event, string $status, string $reason): void
    {
        DB::transaction(function () use ($user, $event, $status, $reason): void {
            $e = InnovationEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
            $allowed = ['draft' => ['submitted', 'archived'], 'returned' => ['submitted', 'archived'], 'submitted' => ['approved', 'returned', 'rejected'], 'rejected' => ['draft', 'archived'], 'approved' => ['published', 'draft', 'archived'], 'published' => ['unpublished', 'completed'], 'unpublished' => ['published', 'draft', 'archived'], 'completed' => ['archived']];
            abort_unless(in_array($status, $allowed[$e->status] ?? [], true), 422);
            if (in_array($status, ['approved', 'returned', 'rejected', 'published', 'unpublished'], true)) {
                abort_unless(NetworkAccess::eventManage($user, $e, 'events.publish'), 403);
            } else {
                Gate::forUser($user)->authorize('update', $e);
            }
            if (in_array($status, ['approved', 'returned', 'rejected'], true)) {
                abort_if($e->created_by === $user->id, 403, 'Event publication requires independent approval.');
            }
            if ($status === 'approved') {
                $e->approved_by = $user->id;
            }
            if ($status === 'published') {
                abort_unless($e->approved_by && $e->ends_at->isFuture() && NetworkAccess::publicScopeAllowed($e->institution, $e->visibility), 422);
                if ($e->innovation_challenge_id) {
                    abort_unless(in_array($e->challenge->status, ['published', 'closed', 'evaluation', 'results_published'], true), 422, 'Publish the linked challenge first.');
                }
                $e->published_at = now();
            }
            if ($status === 'completed') {
                abort_unless($e->ends_at->isPast(), 422, 'Record completion after the event ends.');
            }
            $e->status = $status;
            if ($status === 'draft') {
                $e->approved_by = null;
            }$e->save();
            if (! $e->innovation_challenge_id) {
                $o = $e->opportunity;
                $o->status = match ($status) {
                    'published' => 'published','completed' => 'closed','unpublished' => 'approved',default => $status
                };
                $o->approved_by = $e->approved_by;
                if ($status === 'published') {
                    $o->published_at = now();
                }$o->save();
            }
            NetworkSignals::record('event.'.$status, $e, $e->institution_id, ['status' => $status, 'reason' => $reason]);
            $ids = [$e->created_by];
            if ($status === 'submitted') {
                $ids = NetworkSignals::reviewers($e->institution, 'events.publish');
            }
            NetworkSignals::send($ids, 'network_event', $e, $status.':'.$e->updated_at->format('U.u'), 'Event '.$status);
        });
    }

    public function register(User $user, InnovationEvent $event, int $institutionId): EventRegistration
    {
        abort_unless(NetworkAccess::events($user, false)->whereKey($event->id)->exists() && $event->status === 'published' && $event->starts_at->isFuture() && InstitutionAccess::membership(Institution::findOrFail($institutionId), $user), 403, 'Registration requires an active institutional identity and an open event.');
        if ($event->visibility === 'institution') {
            abort_unless($institutionId === $event->institution_id, 403);
        }

        return DB::transaction(function () use ($user, $event, $institutionId): EventRegistration {
            $e = InnovationEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
            abort_unless($e->status === 'published' && $e->starts_at->isFuture(), 422);
            $registration = $e->registrations()->firstOrNew(['user_id' => $user->id]);
            if ($registration->exists && in_array($registration->status, ['registered', 'attended'], true)) {
                return $registration;
            }
            abort_if($e->capacity && $e->registrations()->whereIn('status', ['registered', 'attended'])->count() >= $e->capacity, 422, 'This event has reached its registration capacity.');
            $existing = OpportunityApplication::where('opportunity_id', $e->opportunity_id)->where('applicant_id', $user->id)->where('status', 'submitted')->value('id');
            $registration->fill(['institution_id' => $institutionId, 'opportunity_application_id' => $existing, 'status' => 'registered', 'registered_at' => now(), 'attended_at' => null, 'attendance_by' => null])->save();
            NetworkSignals::record('event.registered', $e, $e->institution_id, ['registration_id' => $registration->id]);
            NetworkSignals::send([$user->id], 'network_event', $e, 'registered:'.$registration->id.':'.$registration->registered_at->format('U.u'), 'Event registration confirmed');

            return $registration;
        });
    }

    public function cancel(User $user, EventRegistration $registration): void
    {
        abort_unless($registration->user_id === $user->id && $registration->event->starts_at->isFuture() && $registration->status === 'registered' && InstitutionAccess::membership($registration->institution, $user), 403);
        $registration->update(['status' => 'cancelled']);
        NetworkSignals::record('event.registration_cancelled', $registration->event, $registration->event->institution_id, ['registration_id' => $registration->id]);
    }

    public function attendance(User $user, EventRegistration $registration, bool $attended): void
    {
        $event = $registration->event;
        Gate::forUser($user)->authorize('update', $event);
        abort_unless(in_array($event->status, ['published', 'completed'], true) && $event->starts_at->lte(now()) && in_array($registration->status, ['registered', 'attended'], true), 422);
        $registration->update(['status' => $attended ? 'attended' : 'registered', 'attended_at' => $attended ? now() : null, 'attendance_by' => $user->id]);
        NetworkSignals::record('event.attendance_recorded', $event, $event->institution_id, ['registration_id' => $registration->id, 'status' => $registration->status]);
    }

    public function feature(User $user, InnovationEvent $event, array $data): ExhibitionProject
    {
        Gate::forUser($user)->authorize('update', $event);
        abort_unless(in_array($event->status, ['draft', 'approved', 'published'], true), 422);
        $publication = NetworkPublication::findOrFail($data['network_publication_id']);
        abort_unless(NetworkAccess::publicationVisible($user, $publication), 403);
        $allowed = match ($event->visibility) {
            'public' => ['public'],'network' => ['network', 'public'],default => ['institution', 'network', 'public']
        };
        abort_unless(in_array($publication->visibility, $allowed, true) && ($publication->visibility !== 'institution' || $publication->institution_id === $event->institution_id), 422, 'Only projects already approved for the event audience can be featured.');
        $representative = User::findOrFail($data['representative_id']);
        abort_unless(NetworkAccess::publicationEdit($representative, $publication), 422, 'Select a current project representative.');
        $slot = empty($data['presentation_at']) ? null : CarbonImmutable::parse($data['presentation_at'], $event->timezone)->utc();
        if ($slot) {
            abort_unless($slot->gte($event->starts_at) && $slot->lte($event->ends_at), 422);
        }
        $exhibit = $event->exhibits()->updateOrCreate(['network_publication_id' => $publication->id], ['representative_id' => $representative->id, 'category' => $data['category'], 'sort_order' => $data['sort_order'] ?? 0, 'presentation_at' => $slot, 'demo_url' => $data['demo_url'] ?? null]);
        NetworkSignals::record('event.project_featured', $event, $event->institution_id, ['publication_id' => $publication->id]);

        return $exhibit;
    }

    public function reschedule(User $user, InnovationEvent $event, array $data): void
    {
        Gate::forUser($user)->authorize('update', $event);
        abort_unless($event->status === 'published' && $event->starts_at->isFuture(), 422);
        $a = CarbonImmutable::parse($data['starts_at'], $event->timezone)->utc();
        $b = CarbonImmutable::parse($data['ends_at'], $event->timezone)->utc();
        abort_unless($a->isFuture() && $b->gt($a), 422);
        if ($event->innovation_challenge_id) {
            abort_unless($a->gte($event->challenge->opportunity->deadline_at), 422, 'A linked exhibition must follow the challenge deadline.');
        }
        $old = ['starts_at' => $event->starts_at->toIso8601String(), 'ends_at' => $event->ends_at->toIso8601String()];
        $event->update(['starts_at' => $a, 'ends_at' => $b, 'agenda' => []]);
        if (! $event->innovation_challenge_id) {
            $event->opportunity->update(['deadline_at' => $a, 'event_starts_at' => $a->toDateString(), 'event_ends_at' => $b->toDateString()]);
        }
        NetworkSignals::record('event.rescheduled',$event,$event->institution_id,['previous' => $old, 'reason' => $data['reason']]);
        NetworkSignals::send($event->registrations()->whereIn('status',['registered', 'attended'])->pluck('user_id')->all(),'network_event',$event,'rescheduled:'.$event->updated_at->format('U.u'),'Event schedule changed; review the updated agenda');
    }
}

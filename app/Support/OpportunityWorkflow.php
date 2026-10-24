<?php

namespace App\Support;

use App\Models\Institution;
use App\Models\Membership;
use App\Models\Opportunity;
use App\Models\OpportunityCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OpportunityWorkflow
{
    public const TARGETS = ['role' => 'roles', 'class' => 'academic_classes', 'programme' => 'programmes', 'academic_year' => 'academic_years', 'intake' => 'intakes', 'group' => 'project_groups'];

    public function save(array $data, ?Opportunity $opportunity = null): Opportunity
    {
        $user = auth()->user();
        foreach (['audience', 'eligibility'] as $field) {
            $data[$field] = array_map(fn ($ids) => array_map('intval', $ids), $data[$field] ?? []);
        }
        $institution = empty($data['institution_id']) ? null : Institution::findOrFail($data['institution_id']);
        if ($opportunity) {
            Gate::authorize('update', $opportunity);
            abort_unless((int) $opportunity->institution_id === (int) $institution?->id, 422, 'Ownership cannot be transferred.');
            abort_unless(in_array($opportunity->status, ['draft', 'returned'], true), 422, 'Return to draft before editing.');
        } else {
            abort_unless($institution ? InnovationAccess::can($institution, 'opportunities.create', $user) && InnovationAccess::can($institution, 'opportunities.view', $user) : InstitutionAccess::system($user), 403);
        }
        abort_unless(OpportunityCategory::whereKey($data['category_id'])->where('active', true)->exists(), 422, 'Choose an active category.');
        abort_unless($institution ? in_array($data['visibility'], ['institution', 'restricted'], true) : $data['visibility'] === 'platform', 422, 'Choose visibility within the owning workspace.');
        foreach (['audience', 'eligibility'] as $field) {
            foreach ($data[$field] ?? [] as $kind => $ids) {
                $query = DB::table(self::TARGETS[$kind])->whereIn('id', $ids);
                if ($kind === 'role') {
                    $query->where('scope', 'institution');
                } else {
                    abort_unless($institution, 422, 'Academic restrictions require an institutional workspace.');
                    $query->where('institution_id', $institution->id);
                }
                abort_unless($query->count() === count($ids), 422, 'Audience and eligibility references must belong to the selected workspace.');
            }
        }
        abort_if($data['visibility'] === 'restricted' && ! collect($data['audience'] ?? [])->flatten()->count(), 422, 'A restricted opportunity requires an audience.');
        $questions = collect($data['questions'] ?? [])->map(function ($question, $index): array {
            $options = array_values(array_unique(array_filter(array_map('trim', explode(',', $question['options_text'] ?? '')))));
            abort_if($question['type'] === 'select' && (count($options) < 2 || count($options) > 20), 422, 'Select questions require 2 to 20 options.');

            return ['label' => $question['label'], 'type' => $question['type'], 'required' => (bool) ($question['required'] ?? false), 'options' => json_encode($options), 'sort_order' => $index];
        })->all();
        $oldPath = $opportunity?->cover_path;
        $newPath = isset($data['cover']) ? $data['cover']->store('opportunity-covers', 'local') : null;
        try {
            $result = DB::transaction(function () use ($data, $opportunity, $institution, $questions, $newPath): Opportunity {
                $opportunity ??= new Opportunity(['identifier' => (string) Str::ulid(), 'created_by' => auth()->id(), 'institution_id' => $institution?->id, 'status' => 'draft']);
                if ($opportunity->exists && $opportunity->applications()->exists()) {
                    $old = DB::table('opportunity_questions')->where('opportunity_id', $opportunity->id)->orderBy('sort_order')->get()->map(fn ($q) => ['label' => $q->label, 'type' => $q->type, 'required' => (bool) $q->required, 'options' => json_encode(json_decode($q->options, true) ?? []), 'sort_order' => $q->sort_order])->all();
                    abort_unless($questions === $old && ($data['eligibility'] ?? []) === ($opportunity->eligibility ?? []) && (bool) ($data['allow_groups'] ?? false) === $opportunity->allow_groups && $data['application_method'] === $opportunity->application_method, 422, 'Application questions, eligibility and methods are frozen once an application exists.');
                }
                $opportunity->fill(collect($data)->only(['title', 'category_id', 'summary', 'description', 'organiser', 'source_attribution', 'source_url', 'application_method', 'external_url', 'instructions', 'eligibility_text', 'country', 'delivery_mode', 'venue', 'opens_at', 'deadline_at', 'event_starts_at', 'event_ends_at', 'places', 'award_information', 'visibility'])->all());
                $opportunity->eligibility = $data['eligibility'] ?? [];
                $opportunity->allow_groups = (bool) ($data['allow_groups'] ?? false);
                $opportunity->notify_publication = (bool) ($data['notify_publication'] ?? false);
                if ($newPath) {
                    $opportunity->cover_path = $newPath;
                }$opportunity->save();
                if (! $opportunity->applications()->exists()) {
                    DB::table('opportunity_questions')->where('opportunity_id', $opportunity->id)->delete();
                    foreach ($questions as $q) {
                        DB::table('opportunity_questions')->insert($q + ['opportunity_id' => $opportunity->id]);
                    }
                }
                DB::table('opportunity_audiences')->where('opportunity_id', $opportunity->id)->delete();
                foreach ($data['audience'] ?? [] as $kind => $ids) {
                    foreach ($ids as $id) {
                        DB::table('opportunity_audiences')->insert(['opportunity_id' => $opportunity->id, 'kind' => $kind, 'reference_id' => $id]);
                    }
                }
                Audit::record('opportunity.draft_saved', $opportunity, $opportunity->institution_id);

                return $opportunity;
            });
        } catch (\Throwable $exception) {
            if ($newPath) {
                Storage::disk('local')->delete($newPath);
            }throw $exception;
        }
        if ($newPath && $oldPath) {
            Storage::disk('local')->delete($oldPath);
        }

        return $result;
    }

    public function transition(Opportunity $opportunity, string $status, string $reason): void
    {
        if (in_array($status, ['approved', 'returned', 'rejected'], true)) {
            Gate::authorize('review', $opportunity);
        } elseif (in_array($status, ['published', 'closed', 'archived'], true) || $opportunity->status === 'published') {
            Gate::authorize('publish', $opportunity);
        } else {
            Gate::authorize('update', $opportunity);
        }
        DB::transaction(function () use ($opportunity, $status, $reason): void {
            $opportunity = Opportunity::whereKey($opportunity->id)->lockForUpdate()->firstOrFail();
            $allowed = ['draft' => ['submitted', 'archived'], 'returned' => ['submitted', 'archived'], 'submitted' => ['approved', 'returned', 'rejected'], 'approved' => ['published', 'draft', 'archived'], 'published' => ['approved', 'closed'], 'closed' => ['archived'], 'rejected' => ['draft', 'archived'], 'archived' => []];
            abort_unless(in_array($status, $allowed[$opportunity->status] ?? [], true), 422, 'This publication transition is not permitted.');
            if ($status === 'published') {
                abort_if($opportunity->deadline_at?->isPast(), 422, 'Update an expired deadline before publication.');
            }
            $opportunity->status = $status;
            if ($status === 'approved') {
                $opportunity->approved_by = auth()->id();
            }if ($status === 'draft') {
                $opportunity->approved_by = null;
            }if ($status === 'published') {
                $opportunity->published_at = now();
            }$opportunity->save();
            DB::table('opportunity_decisions')->insert(['opportunity_id' => $opportunity->id, 'actor_id' => auth()->id(), 'decision' => $status, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('opportunity.publication_decision', $opportunity, $opportunity->institution_id, ['status' => $status]);
            if ($status === 'published' && $opportunity->notify_publication) {
                OpportunitySignals::publication($opportunity);
            }
        });
    }

    public function manager(Opportunity $opportunity, int $membershipId): void
    {
        abort_unless(OpportunityAccess::manages($opportunity, auth()->user()), 403);
        $member = Membership::with('user')->findOrFail($membershipId);
        abort_unless($member->institution_id === $opportunity->institution_id && OpportunityAccess::permission($opportunity, 'opportunities.manage', $member->user), 422, 'Choose an authorised active opportunity manager from this institution.');
        $opportunity->managers()->syncWithoutDetaching([$member->user_id]);
        Audit::record('opportunity.manager_assigned', $opportunity, $opportunity->institution_id, ['user_id' => $member->user_id]);
    }

    public function removeManager(Opportunity $opportunity, int $userId): void
    {
        abort_unless(OpportunityAccess::manages($opportunity, auth()->user()), 403);
        if ($opportunity->managers()->detach($userId)) {
            Audit::record('opportunity.manager_removed', $opportunity, $opportunity->institution_id, ['user_id' => $userId]);
        }
    }
}

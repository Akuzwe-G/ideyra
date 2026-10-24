<?php

namespace App\Support;

use App\Models\AiConversation;
use App\Models\CvDocument;
use App\Models\Institution;
use App\Models\ProfessionalEntry;
use App\Models\User;

class AiContext
{
    public static function evidenceStatus(ProfessionalEntry $entry): string
    {
        $current = $entry->verifications->contains(fn ($v) => $v->user_id === $entry->profile->user_id && $v->status === 'verified'
            && $v->institution->status === 'active'
            && $v->approved_content === app(CareerWorkflow::class)->entryPayload($entry)
        );

        return $current ? 'Institution-confirmed record; this is not an objective skill score.' : 'Self-reported; no verification asserted';
    }

    public function record(User $user, string $feature, ?int $id): mixed
    {
        abort_if(in_array($feature, ['career', 'opportunity'], true) && $id !== null, 422);

        return match ($feature) {
            'idea' => InnovationAccess::ideas($user)->findOrFail($id),
            'project' => InnovationAccess::projects($user)->findOrFail($id),
            'supervision' => InnovationAccess::supervised($user)->findOrFail($id),
            'cv' => CvDocument::where('user_id', $user->id)->findOrFail($id),
            default => null,
        };
    }

    public function fields(string $feature): array
    {
        return match ($feature) {
            'idea' => ['title', 'problem', 'beneficiaries', 'solution'],
            'project','supervision' => ['title', 'problem', 'objectives', 'outcomes', 'schedule', 'progress', 'milestones', 'tasks', 'deliverables'],
            'cv' => array_keys(config('career.sections')),
            default => [],
        };
    }

    public function build(User $user, string $feature, ?int $id, ?int $institutionId, array $selected, array $entryIds): array
    {
        $record = $this->record($user, $feature, $id);
        if ($record && array_key_exists('institution_id', $record->getAttributes())) {
            abort_unless((int) $record->institution_id === (int) $institutionId, 403);
        }
        if ($institutionId) {
            abort_unless(InstitutionAccess::membership(Institution::findOrFail($institutionId), $user), 403);
        }
        abort_if(array_diff($selected, $this->fields($feature)), 422, 'Choose supported context fields.');
        $context = [];
        foreach ($selected as $field) {
            $context[$field] = match ($field) {
                'schedule' => ['starts_at' => $record->starts_at?->toDateString(), 'target_at' => $record->target_at?->toDateString()],
                'progress' => $record->progress(),
                'milestones' => $record->milestones()->limit(15)->get(['title', 'due_at', 'status'])->toArray(),
                'tasks' => $record->tasks()->limit(15)->get(['title', 'due_at', 'status', 'priority'])->toArray(),
                'deliverables' => $record->deliverables()->limit(15)->get(['title', 'due_at', 'status'])->toArray(),
                default => $record instanceof CvDocument ? ($record->content[$field] ?? '') : $record->{$field},
            };
        }
        if ($entryIds) {
            abort_unless(in_array($feature, ['career', 'opportunity'], true), 422);
            $entries = ProfessionalEntry::whereHas('profile', fn ($q) => $q->where('user_id', $user->id))->whereIn('id', $entryIds)->get();
            abort_unless($entries->count() === count($entryIds), 403);
            $context['selected_professional_records'] = $entries->map(fn ($e) => ['type' => $e->type, 'title' => $e->title, 'details' => $e->content, 'evidence_status' => self::evidenceStatus($e)])->all();
        }
        if ($feature === 'opportunity') {
            abort_unless($institutionId, 422, 'Select your institution for eligibility checks.');
            $visible = OpportunityAccess::visible($user, false)->limit(30)->get();
            $context['eligible_opportunities'] = $visible->lazy()->filter(fn ($o) => OpportunityAccess::eligible($o, $user, $institutionId))->take(6)->map(fn ($o) => [
                'id' => $o->id, 'title' => $o->title, 'description' => mb_substr($o->description, 0, 500), 'requirements' => mb_substr($o->eligibility_text ?? 'Check all written requirements on the opportunity page.', 0, 300),
                'eligibility' => 'Potentially eligible: structured rules passed; written requirements remain unverified.',
                'deadline' => $o->deadline_at?->toIso8601String(), 'location' => $o->location, 'delivery_mode' => $o->delivery_mode,
            ])->values()->all();
        }

        return $context;
    }

    public function recheck(AiConversation $conversation, User $user): void
    {
        $this->record($user, $conversation->feature, $conversation->related_id);
        if ($conversation->institution_id) {
            abort_unless(InstitutionAccess::membership(Institution::findOrFail($conversation->institution_id), $user), 403);
        }
    }
}

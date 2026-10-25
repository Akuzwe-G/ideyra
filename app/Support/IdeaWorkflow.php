<?php

namespace App\Support;

use App\Models\Idea;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IdeaWorkflow
{
    public function save(array $data, ?Idea $idea = null): Idea
    {
        $user = auth()->user();
        if ($idea) {
            Gate::authorize('update', $idea);
            abort_unless(in_array($idea->status, ['draft', 'developing', 'returned'], true), 422, 'Only drafts or returned ideas can be edited.');
            abort_unless((int) ($data['institution_id'] ?? 0) === (int) $idea->institution_id, 422, 'Workspace changes require an explicit review copy.');
        }
        $institution = empty($data['institution_id']) ? null : Institution::findOrFail($data['institution_id']);
        abort_if($institution && ! InnovationAccess::can($institution, $idea ? 'ideas.edit_own' : 'ideas.create', $user), 403);
        abort_if($data['visibility'] === 'institutional' && ! $institution, 422, 'Choose an institutional workspace, or create a review copy.');
        $ids = [];
        if ($data['visibility'] !== 'private') {
            abort_unless(! empty($data['confirm_share']), 422, 'Explicit sharing consent is required.');
            abort_if($institution && ! InnovationAccess::can($institution, 'ideas.share', $user), 403);
        }
        if ($data['visibility'] === 'shared') {
            $emails = array_values(array_unique(array_filter(array_map(fn ($email) => mb_strtolower(trim($email)), preg_split('/[,;\r\n]+/', $data['collaborator_emails'] ?? '')))));
            foreach ($emails as $email) {
                $target = User::where('email', $email)->where('status', 'active')->first();
                if (! $target || ($institution && ! InstitutionAccess::membership($institution, $target))) {
                    throw ValidationException::withMessages(['collaborator_emails' => 'Every collaborator must be an active identity in the selected context.']);
                }
                if ($target->id !== $user->id) {
                    $ids[] = $target->id;
                }
            }
            if (! $ids) {
                throw ValidationException::withMessages(['collaborator_emails' => 'Select at least one other collaborator by their exact account email.']);
            }
        }
        $tags = array_slice(array_values(array_unique(array_filter(array_map('trim', explode(',', $data['tags_text'] ?? ''))))), 0, 8);
        foreach ($tags as $tag) {
            if (mb_strlen($tag) > 32) {
                throw ValidationException::withMessages(['tags_text' => 'Tags must be 32 characters or fewer.']);
            }
        }

        return DB::transaction(function () use ($data, $idea, $institution, $ids, $tags, $user): Idea {
            $idea ??= new Idea(['identifier' => (string) Str::ulid(), 'owner_id' => $user->id, 'institution_id' => $institution?->id]);
            $idea->fill(collect($data)->only(['title', 'description', 'problem', 'solution', 'beneficiaries', 'category', 'visibility', 'status'])->all());
            $idea->tags = $tags;
            $idea->save();
            $idea->collaborators()->sync($ids);

            return $idea;
        });
    }

    public function publish(Idea $idea, int $institutionId): Idea
    {
        Gate::authorize('update', $idea);
        abort_if($idea->institution_id, 422, 'This idea already has an institutional context.');
        $institution = Institution::findOrFail($institutionId);
        abort_unless(InnovationAccess::can($institution, 'ideas.create', auth()->user()) && InnovationAccess::can($institution, 'ideas.share', auth()->user()), 403);

        return DB::transaction(function () use ($idea, $institution): Idea {
            Idea::whereKey($idea->id)->lockForUpdate()->firstOrFail();
            $existing = Idea::where('source_idea_id', $idea->id)->where('institution_id', $institution->id)->first();
            if ($existing) {
                return $existing;
            }
            $copy = $idea->replicate(['identifier', 'reviewer_id', 'status', 'visibility']);
            $copy->identifier = (string) Str::ulid();
            $copy->institution_id = $institution->id;
            $copy->source_idea_id = $idea->id;
            $copy->visibility = 'institutional';
            $copy->status = 'draft';
            $copy->save();

            return $copy;
        });
    }

    public function submit(Idea $idea, ?int $reviewerId): void
    {
        Gate::authorize('update', $idea);
        abort_unless($idea->institution_id && InnovationAccess::can($idea->institution, 'ideas.submit_for_review', auth()->user()) && InnovationAccess::can($idea->institution, 'ideas.share', auth()->user()), 403);
        if ($reviewerId) {
            $reviewer = User::findOrFail($reviewerId);
            abort_unless($reviewer->id !== $idea->owner_id && InnovationAccess::can($idea->institution, 'ideas.review', $reviewer), 422, 'Choose an authorised reviewer other than the owner.');
        }
        DB::transaction(function () use ($idea, $reviewerId): void {
            $locked = Idea::whereKey($idea->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($locked->status, ['draft', 'developing', 'returned'], true), 422);
            $locked->update(['status' => 'submitted', 'visibility' => 'institutional', 'reviewer_id' => $reviewerId, 'first_submitted_at' => $locked->first_submitted_at ?? now()]);
        });
    }

    public function review(Idea $idea, string $decision, string $feedback): void
    {
        Gate::authorize('review', $idea);
        DB::transaction(function () use ($idea, $decision, $feedback): void {
            $idea = Idea::whereKey($idea->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($idea->status, ['submitted', 'under_review'], true), 422, 'Only submitted ideas can be reviewed.');
            $idea->reviews()->create(['reviewer_id' => auth()->id(), 'decision' => $decision, 'feedback' => $feedback]);
            $idea->update(['status' => $decision]);
            ProjectSignals::send([$idea->owner_id], 'idea', $idea, 'Idea feedback received', 'Your institutional idea has a new review decision.');
        });
    }
}

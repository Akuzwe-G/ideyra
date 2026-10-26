<?php

namespace App\Support;

use App\Models\ChallengeEvaluation;
use App\Models\ChallengeSubmission;
use App\Models\CollaborationWorkspace;
use App\Models\InnovationChallenge;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\OpportunityApplication;
use App\Models\OpportunityCategory;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ChallengeWorkflow
{
    public function save(User $user, array $data, ?InnovationChallenge $challenge = null): InnovationChallenge
    {
        $institution = Institution::findOrFail($data['institution_id']);
        abort_unless(NetworkAccess::can($user, $institution, $challenge ? 'challenges.manage' : 'challenges.create'), 403);
        abort_unless(abs(array_sum(array_column($data['criteria'], 'weight')) - 100) < 0.001, 422, 'Judging weights must total exactly 100 percent.');
        abort_unless(NetworkAccess::publicScopeAllowed($institution, $data['visibility']), 422, 'Institution policy does not permit this audience.');
        abort_if(($data['allow_cross_institution'] ?? false) && ! in_array($data['visibility'], ['network', 'public'], true), 422, 'Cross-institution teams require network or public discovery.');
        $tz = config('intelligence.timezone');
        $opens = CarbonImmutable::parse($data['opens_at'], $tz)->utc();
        $deadline = CarbonImmutable::parse($data['deadline_at'], $tz)->utc();
        abort_if($opens->gte($deadline), 422);

        return DB::transaction(function () use ($user, $institution, $data, $challenge, $opens, $deadline): InnovationChallenge {
            if ($challenge) {
                Gate::forUser($user)->authorize('update', $challenge);
                $challenge = InnovationChallenge::whereKey($challenge->id)->lockForUpdate()->firstOrFail();
                abort_unless($challenge->institution_id === $institution->id && in_array($challenge->status, ['draft', 'returned'], true), 422);
                abort_if($challenge->opportunity->applications()->exists(), 422, 'Eligibility and scoring are frozen once registration begins.');
                $opportunity = $challenge->opportunity;
                $opportunity->fill(collect($data)->only(['title', 'summary', 'description', 'organiser', 'eligibility_text', 'award_information'])->all());
                $opportunity->fill(['opens_at' => $opens, 'deadline_at' => $deadline, 'allow_groups' => (bool) ($data['allow_groups'] ?? false), 'instructions' => $data['rules'], 'status' => 'draft', 'approved_by' => null])->save();
            } else {
                $opportunity = app(OpportunityWorkflow::class)->save(['institution_id' => $institution->id, 'title' => $data['title'], 'summary' => $data['summary'], 'description' => $data['description'], 'organiser' => $data['organiser'], 'category_id' => OpportunityCategory::where('slug', 'innovation-challenges')->sole()->id, 'source_attribution' => 'Institution organiser-provided innovation challenge', 'application_method' => 'internal', 'instructions' => $data['rules'], 'eligibility_text' => $data['eligibility_text'], 'eligibility' => [], 'delivery_mode' => 'hybrid', 'opens_at' => $opens, 'deadline_at' => $deadline, 'award_information' => $data['award_information'] ?? null, 'visibility' => 'institution', 'allow_groups' => (bool) ($data['allow_groups'] ?? false)]);
                $opportunity->update(['network_type' => 'challenge']);
                $challenge = new InnovationChallenge(['opportunity_id' => $opportunity->id, 'institution_id' => $institution->id, 'created_by' => $user->id]);
            }
            $challenge->fill(['kind' => $data['kind'], 'visibility' => $data['visibility'], 'rules' => $data['rules'], 'allow_cross_institution' => (bool) ($data['allow_cross_institution'] ?? false), 'event_at' => empty($data['event_at']) ? null : CarbonImmutable::parse($data['event_at'], config('intelligence.timezone'))->utc(), 'status' => 'draft', 'approved_by' => null, 'results_state' => 'preliminary']);
            $challenge->save();
            $challenge->criteria()->delete();
            foreach ($data['criteria'] as $criterion) {
                $challenge->criteria()->create(collect($criterion)->only(['name', 'weight'])->all());
            }
            NetworkSignals::record('challenge.draft_saved', $challenge, $institution->id);

            return $challenge;
        });
    }

    public function transition(User $user, InnovationChallenge $challenge, string $status, string $reason): void
    {
        DB::transaction(function () use ($user, $challenge, $status, $reason): void {
            $c = InnovationChallenge::whereKey($challenge->id)->lockForUpdate()->firstOrFail();
            $o = $c->opportunity;
            $allowed = ['draft' => ['submitted', 'archived'], 'returned' => ['submitted', 'archived'], 'submitted' => ['approved', 'returned', 'rejected'], 'rejected' => ['draft', 'archived'], 'approved' => ['published', 'draft', 'archived'], 'published' => ['closed', 'unpublished'], 'closed' => ['evaluation', 'archived'], 'evaluation' => ['results_published', 'unpublished'], 'results_published' => ['unpublished', 'archived'], 'unpublished' => ['published', 'archived']];
            abort_unless(in_array($status, $allowed[$c->status] ?? [], true), 422, 'This challenge transition is not permitted.');
            if (in_array($status, ['approved', 'returned', 'rejected', 'published', 'unpublished', 'results_published'], true)) {
                abort_unless(NetworkAccess::challengeManage($user, $c, 'challenges.publish'), 403);
            } else {
                Gate::forUser($user)->authorize('update', $c);
            }
            if (in_array($status, ['approved', 'returned', 'rejected'], true)) {
                abort_if($c->created_by === $user->id, 403, 'Independent challenge approval is required.');
            }
            if ($status === 'approved') {
                $c->approved_by = $user->id;
                $o->approved_by = $user->id;
            }
            if ($status === 'published') {
                abort_unless($c->approved_by && NetworkAccess::publicScopeAllowed($c->institution, $c->visibility) && $o->deadline_at->isFuture(), 422);
                $o->status = 'published';
                $o->published_at = now();
            } elseif ($status === 'closed') {
                $o->status = 'closed';
            } elseif ($status === 'evaluation') {
                abort_unless($o->deadline_at->isPast(), 422, 'Evaluation begins after the submission deadline.');
            } elseif ($status === 'results_published') {
                abort_unless($c->results_state === 'final' && $c->results_snapshot, 422, 'Finalise the complete scoring model before publication.');
                $c->results_state = 'published';
                $c->results_published_at = now();
            } elseif ($status === 'unpublished') {
                $o->status = 'approved';
            } elseif ($status === 'draft') {
                abort_if($o->applications()->exists(), 422);
                $c->approved_by = null;
                $o->status = 'draft';
                $o->approved_by = null;
            } elseif ($status === 'archived') {
                $o->status = 'archived';
            } else {
                $o->status = $status;
            }
            $c->status = $status;
            $c->save();
            $o->save();
            NetworkSignals::record('challenge.'.$status, $c, $c->institution_id, ['status' => $status, 'reason' => $reason]);
            $ids = [$c->created_by];
            if ($status === 'submitted') {
                $ids = NetworkSignals::reviewers($c->institution, 'challenges.publish');
            }
            if ($status === 'results_published') {
                $ids = [...$ids, ...DB::table('application_participants')->whereIn('application_id', $o->applications()->select('id'))->pluck('user_id')->all()];
            }
            NetworkSignals::send($ids, 'network_challenge', $c, $status.':'.$c->updated_at->format('U.u'), 'Challenge '.$status);
        });
    }

    public function register(User $user, InnovationChallenge $challenge, array $data): OpportunityApplication
    {
        Gate::forUser($user)->authorize('view', $challenge);
        abort_unless($challenge->status === 'published' && $challenge->opportunity->isOpen(), 422, 'Registration is outside the challenge opening period.');
        if (empty($data['collaboration_workspace_id'])) {
            $application = app(OpportunityApplicationWorkflow::class)->save($challenge->opportunity, $data);
            NetworkSignals::record('challenge.registration_draft', $challenge, $challenge->institution_id, ['application_id' => $application->id]);

            return $application;
        }
        $workspace = CollaborationWorkspace::findOrFail($data['collaboration_workspace_id']);
        abort_unless($challenge->allow_cross_institution && NetworkAccess::member($user, $workspace, true) && $workspace->status === 'active', 403);
        $ids = array_values(array_unique(array_map('intval', $data['participant_ids'] ?? [])));
        abort_unless($ids && count($ids) <= 20 && in_array($user->id, $ids, true), 422, 'Include the representative and at most twenty consenting students.');
        foreach ($ids as $id) {
            $participant = User::findOrFail($id);
            $member = NetworkAccess::member($participant, $workspace);
            abort_unless($member && OpportunityAccess::eligible($challenge->opportunity, $participant, $member->institution_id), 422, 'Every cross-institution participant must remain eligible.');
        }
        $own = NetworkAccess::member($user, $workspace);
        abort_unless($own->institution_id === (int) $data['institution_id'], 422);

        return DB::transaction(function () use ($challenge, $workspace, $user, $data, $ids): OpportunityApplication {
            Opportunity::whereKey($challenge->opportunity_id)->lockForUpdate()->firstOrFail();
            $subject = 'collaboration:'.$workspace->id;
            abort_if($challenge->opportunity->applications()->where('subject_key', $subject)->exists(), 422, 'This collaboration already has a registration.');
            $app = $challenge->opportunity->applications()->create(['identifier' => (string) Str::ulid(), 'applicant_id' => $user->id, 'institution_id' => $data['institution_id'], 'collaboration_workspace_id' => $workspace->id, 'subject_key' => $subject, 'motivation' => $data['motivation'], 'skills' => $data['skills'] ?? null, 'participant_ids' => $ids, 'status' => 'draft']);
            foreach ($ids as $id) {
                DB::table('opportunity_consents')->insert(['application_id' => $app->id, 'user_id' => $id, 'consented_at' => $id === $user->id ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
            }
            NetworkSignals::record('challenge.cross_registration_draft', $challenge, $challenge->institution_id, ['application_id' => $app->id]);
            NetworkSignals::send(array_diff($ids, [$user->id]), 'network_challenge', $challenge, 'consent:'.$app->id, 'Challenge team consent requested');

            return $app;
        });
    }

    public function teamConsent(User $user, OpportunityApplication $application, bool $consent): void
    {
        if (! $application->collaboration_workspace_id) {
            app(OpportunityApplicationWorkflow::class)->consent($application, $consent);

            return;
        }
        $workspace = CollaborationWorkspace::findOrFail($application->collaboration_workspace_id);
        abort_unless($application->status === 'draft' && in_array($user->id, $application->participant_ids ?? [], true) && NetworkAccess::member($user, $workspace), 403);
        DB::table('opportunity_consents')->where('application_id', $application->id)->where('user_id', $user->id)->update(['consented_at' => $consent ? now() : null, 'updated_at' => now()]);
    }

    public function commitRegistration(User $user, InnovationChallenge $challenge, OpportunityApplication $application): void
    {
        abort_unless($application->opportunity_id === $challenge->opportunity_id && $application->applicant_id === $user->id && $challenge->status === 'published' && $challenge->opportunity->isOpen(), 422);
        if (! $application->collaboration_workspace_id) {
            app(OpportunityApplicationWorkflow::class)->submit($application);
        } else {
            DB::transaction(function () use ($application, $user, $challenge): void {
                $a = OpportunityApplication::whereKey($application->id)->lockForUpdate()->firstOrFail();
                abort_unless($a->status === 'draft', 422);
                $workspace = CollaborationWorkspace::findOrFail($a->collaboration_workspace_id);
                abort_unless(NetworkAccess::member($user, $workspace, true) && $workspace->status === 'active', 403);
                $snapshot = [];
                foreach ($a->participant_ids as $id) {
                    $u = User::findOrFail($id);
                    $member = NetworkAccess::member($u, $workspace);
                    abort_unless($member && OpportunityAccess::eligible($challenge->opportunity, $u, $member->institution_id), 422);
                    abort_unless(DB::table('opportunity_consents')->where('application_id', $a->id)->where('user_id', $id)->whereNotNull('consented_at')->exists(), 422, 'Every named team participant must consent.');
                    $snapshot[] = ['user_id' => $id, 'name' => $u->name, 'institution_id' => $member->institution_id];
                    $programme = DB::table('class_assignments as ca')->join('academic_classes as ac', 'ac.id', '=', 'ca.academic_class_id')->where('ca.membership_id', InstitutionAccess::membership($member->institution, $u)->id)->where('ca.assignment', 'student')->value('ac.programme_id');
                    DB::table('application_participants')->updateOrInsert(['application_id' => $a->id, 'user_id' => $id], ['institution_id' => $member->institution_id, 'programme_id' => $programme]);
                }
                $a->update(['status' => 'submitted', 'participant_snapshot' => $snapshot, 'submitted_at' => now()]);
                DB::table('application_snapshots')->insert(['application_id' => $a->id, 'version' => 1, 'payload' => Crypt::encryptString(json_encode(['participants' => $snapshot, 'motivation' => $a->motivation, 'skills' => $a->skills, 'consent_policy_version' => 'network-1'])), 'created_at' => now(), 'updated_at' => now()]);
            });
        }
        NetworkSignals::record('challenge.registration_confirmed', $challenge, $challenge->institution_id, ['application_id' => $application->id]);
        NetworkSignals::send($application->participant_ids, 'network_challenge', $challenge, 'registered:'.$application->id, 'Challenge registration confirmed');
    }

    public function submit(User $user, InnovationChallenge $challenge, OpportunityApplication $application, array $data, ?UploadedFile $file = null): ChallengeSubmission
    {
        abort_unless($application->opportunity_id === $challenge->opportunity_id && $application->applicant_id === $user->id && $application->status === 'submitted' && $challenge->status === 'published' && $challenge->opportunity->isOpen(), 422, 'An eligible confirmed registration and an open submission window are required.');
        if ($application->collaboration_workspace_id) {
            $workspace = CollaborationWorkspace::findOrFail($application->collaboration_workspace_id);
            abort_unless(NetworkAccess::member($user, $workspace, true) && $workspace->status === 'active', 403);
        } else {
            Gate::forUser($user)->authorize('update', $application);
        }
        foreach ($application->participant_ids as $id) {
            $participant = User::findOrFail($id);
            $iid = collect($application->participant_snapshot ?? [])->firstWhere('user_id', $id)['institution_id'] ?? $application->institution_id;
            abort_unless(OpportunityAccess::eligible($challenge->opportunity, $participant, $iid), 422, 'Participation eligibility changed.');
        }
        $project = Project::findOrFail($data['project_id']);
        $linked = $application->collaboration_workspace_id && $workspace->publication->project_id === $project->id && NetworkAccess::publicationVisible($user, $workspace->publication);
        abort_unless($linked || InnovationAccess::projects($user)->whereKey($project->id)->exists(), 403);
        if ($application->group_id) {
            abort_unless($project->group_id === $application->group_id, 422, 'Use the registered group project.');
        }
        $path = $file?->store('network/challenge-submissions', 'local');
        try {
            return DB::transaction(function () use ($challenge, $application, $user, $data, $project, $path, $file): ChallengeSubmission {
                Opportunity::whereKey($challenge->opportunity_id)->lockForUpdate()->firstOrFail();
                abort_unless($challenge->fresh()->status === 'published' && $challenge->opportunity->fresh()->isOpen(), 422);
                $existing = ChallengeSubmission::where('opportunity_application_id', $application->id)->lockForUpdate()->first();
                $version = ($existing?->version ?? 0) + 1;
                $snapshot = collect($data)->only(['title', 'summary', 'description', 'demo_url'])->all() + ['participant_ids' => $application->participant_ids, 'project_id' => $project->id, 'file_path' => $path, 'file_name' => $file?->getClientOriginalName(), 'consented_at' => now()->toIso8601String()];
                $submission = $existing ?? new ChallengeSubmission(['innovation_challenge_id' => $challenge->id, 'opportunity_application_id' => $application->id]);
                $submission->fill(['project_id' => $project->id, 'snapshot' => $snapshot, 'version' => $version, 'submitted_at' => now(), 'status' => 'submitted'])->save();
                DB::table('challenge_submission_versions')->insert(['challenge_submission_id' => $submission->id, 'version' => $version, 'snapshot' => Crypt::encryptString(json_encode($snapshot)), 'submitted_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
                NetworkSignals::record('challenge.submission_received', $challenge, $challenge->institution_id, ['submission_id' => $submission->id, 'version' => $version]);
                NetworkSignals::send([$user->id, $challenge->created_by], 'network_challenge', $challenge, 'submission:'.$submission->id.':'.$version, 'Challenge submission received');

                return $submission;
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }throw $e;
        }
    }

    public function withdraw(User $user, InnovationChallenge $challenge, OpportunityApplication $application): void
    {
        abort_unless($application->opportunity_id === $challenge->opportunity_id && $application->applicant_id === $user->id && $challenge->opportunity->deadline_at->isFuture() && in_array($application->status, ['draft', 'submitted'], true), 403);
        if ($application->collaboration_workspace_id) {
            abort_unless(NetworkAccess::member($user, CollaborationWorkspace::findOrFail($application->collaboration_workspace_id), true), 403);
            $application->update(['status' => 'withdrawn']);
        } else {
            app(OpportunityApplicationWorkflow::class)->withdraw($application);
        }
        ChallengeSubmission::where('opportunity_application_id', $application->id)->update(['status' => 'withdrawn']);
        NetworkSignals::record('challenge.registration_withdrawn', $challenge, $challenge->institution_id, ['application_id' => $application->id]);
    }

    public function assignJudge(User $user, InnovationChallenge $challenge, User $judge, bool $active = true): void
    {
        Gate::forUser($user)->authorize('update', $challenge);
        abort_if(in_array($challenge->results_state, ['final', 'published'], true), 422);
        abort_unless(InnovationAccess::memberships($judge)->contains(fn ($m) => NetworkAccess::can($judge, $m->institution, 'challenges.judge') && ($m->institution_id === $challenge->institution_id || NetworkAccess::setting($m->institution_id)->allow_network)), 422, 'Choose an authorised active judge.');
        abort_if($challenge->judges()->count() >= 20 && ! $challenge->judges()->where('user_id', $judge->id)->exists(), 422, 'At most twenty judges may be assigned.');
        $challenge->judges()->updateOrCreate(['user_id' => $judge->id], ['assigned_by' => $user->id, 'active' => $active]);
        NetworkSignals::record('challenge.judge_assigned', $challenge, $challenge->institution_id, ['user_id' => $judge->id]);
    }

    public function conflict(User $judge, ChallengeSubmission $submission): bool
    {
        return in_array($judge->id, $submission->snapshot['participant_ids'] ?? [], true) || $submission->project->owner_id === $judge->id || in_array($judge->id, NetworkAccess::contributors($submission->project), true) || DB::table('challenge_conflicts')->where('challenge_submission_id', $submission->id)->where('user_id', $judge->id)->exists();
    }

    public function evaluate(User $user, ChallengeSubmission $submission, array $data): ChallengeEvaluation
    {
        $challenge = $submission->challenge;
        Gate::forUser($user)->authorize('judge', $challenge);
        abort_unless($challenge->status === 'evaluation' && $challenge->results_state === 'preliminary' && $submission->status === 'submitted' && $submission->application->status === 'submitted', 422);
        abort_if($this->conflict($user, $submission), 403, 'You cannot evaluate a submission involving yourself or a declared conflict.');
        $criteria = $challenge->criteria()->get();
        $scores = $data['scores'];
        abort_unless(count($scores) === $criteria->count() && ! array_diff(array_keys($scores), $criteria->pluck('id')->all()), 422, 'Score every configured criterion only.');
        $total = 0;
        foreach ($criteria as $c) {
            $score = $scores[$c->id]['score'];
            abort_unless(is_numeric($score) && $score >= 0 && $score <= 10, 422);
            $total += (float) $score / 10 * $c->weight;
        }

        return DB::transaction(function () use ($challenge, $submission, $user, $data, $scores, $total): ChallengeEvaluation {
            InnovationChallenge::whereKey($challenge->id)->lockForUpdate()->firstOrFail();
            $e = ChallengeEvaluation::firstOrNew(['challenge_submission_id' => $submission->id, 'user_id' => $user->id]);
            abort_if($e->exists && $e->status === 'final', 422, 'Final evaluations are immutable.');
            $before = $e->exists ? $e->scores : null;
            $e->fill(['scores' => $scores, 'feedback' => $data['feedback'], 'total' => round($total, 4), 'status' => $data['final'] ? 'final' : 'draft', 'finalised_at' => $data['final'] ? now() : null])->save();
            NetworkSignals::record('evaluation.saved', $e, $challenge->institution_id, ['status' => $e->status, 'previous_scores' => $before, 'scores' => $scores, 'total' => $total]);
            if ($data['final']) {
                NetworkSignals::send([$challenge->created_by], 'network_challenge', $challenge, 'evaluation:'.$e->id, 'An assigned judge completed an evaluation');
            }

            return $e;
        });
    }

    public function declareConflict(User $user, ChallengeSubmission $submission, string $reason): void
    {
        Gate::forUser($user)->authorize('judge', $submission->challenge);
        abort_unless($submission->challenge->results_state === 'preliminary', 422);
        DB::table('challenge_conflicts')->updateOrInsert(['challenge_submission_id' => $submission->id, 'user_id' => $user->id], ['reason' => Crypt::encryptString($reason), 'created_at' => now(), 'updated_at' => now()]);
        $submission->evaluations()->where('user_id', $user->id)->update(['status' => 'void']);
        NetworkSignals::record('evaluation.conflict_declared', $submission, $submission->challenge->institution_id, ['user_id' => $user->id]);
    }

    public function finalise(User $user, InnovationChallenge $challenge, string $reason): void
    {
        Gate::forUser($user)->authorize('update', $challenge);
        DB::transaction(function () use ($challenge, $reason): void {
            $c = InnovationChallenge::whereKey($challenge->id)->lockForUpdate()->firstOrFail();
            abort_unless($c->status === 'evaluation' && $c->results_state === 'preliminary', 422);
            $judges = $c->judges()->where('active', true)->with('user')->get()->filter(fn ($j) => NetworkAccess::judge($j->user, $c))->pluck('user');
            $results = [];
            $c->submissions()->where('status', 'submitted')->whereHas('application', fn ($q) => $q->where('status', 'submitted'))->with('application', 'project')->chunkById(100, function ($submissions) use (&$results, $judges): void {
                foreach ($submissions as $s) {
                    $valid = $judges->reject(fn ($judge) => $this->conflict($judge, $s))->pluck('id')->all();
                    abort_unless($valid, 422, 'Each submission requires at least one independent, non-conflicted judge.');
                    $scores = $s->evaluations()->whereIn('user_id',$valid)->where('status','final')->get(['user_id', 'total']);
                    abort_unless($scores->count() === count($valid),422,'Every eligible assigned judge must submit a final evaluation.');
                    $results[] = ['submission_id' => $s->id, 'score' => round($scores->avg('total'),4), 'judges' => count($valid)];
                }
            });
            abort_unless($results,422,'No eligible submissions are ready for final results.');
            usort($results,fn ($a,$b) => ($b['score'] <=> $a['score']) ?: ($a['submission_id'] <=> $b['submission_id']));
            $last = null;
            $place = 0;
            foreach ($results as $i => &$row) {
                if ($row['score'] !== $last) {
                    $place = $i + 1;
                }$row['place'] = $place;
                $last = $row['score'];
            }unset($row);
            $c->update(['results_snapshot' => ['criteria' => $c->criteria()->get(['id', 'name', 'weight'])->toArray(), 'entries' => $results, 'finalised_at' => now()->toIso8601String()], 'results_state' => 'final']);
            NetworkSignals::record('challenge.results_finalised',$c,$c->institution_id,['reason' => $reason]);
        });
    }
}

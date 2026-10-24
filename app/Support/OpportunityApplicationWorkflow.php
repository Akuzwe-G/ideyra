<?php

namespace App\Support;

use App\Models\Opportunity;
use App\Models\OpportunityApplication as Application;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OpportunityApplicationWorkflow
{
    public function save(Opportunity $opportunity, array $data, ?Application $application = null): Application
    {
        $user = auth()->user();
        Gate::authorize('view', $opportunity);
        abort_unless(OpportunityAccess::eligible($opportunity, $user, (int) $data['institution_id']), 403, 'You must be an eligible active student in the selected institution.');
        abort_if($opportunity->application_method !== 'internal', 422, 'Use the official external application page. External applications are self-reported separately.');
        if ($application) {
            Gate::authorize('update', $application);
            abort_unless(in_array($application->status, ['draft', 'more_information_requested'], true), 422, 'Submitted applications cannot be edited.');
            abort_unless($application->opportunity_id === $opportunity->id && $application->institution_id === (int) $data['institution_id'] && (int) $application->group_id === (int) ($data['group_id'] ?? 0), 422, 'Application ownership and context cannot change.');
        }
        $group = empty($data['group_id']) ? null : ProjectGroup::findOrFail($data['group_id']);
        $participants = [$user->id];
        if ($group) {
            abort_unless($opportunity->allow_groups && $group->institution_id === (int) $data['institution_id'] && $group->status === 'active' && InnovationAccess::groups($user)->whereKey($group->id)->exists(), 403);
            $member = InstitutionAccess::membership($group->institution, $user);
            abort_unless($group->members()->where('membership_id', $member?->id)->where('role', 'leader')->exists(), 403, 'Only the assigned group leader may represent this group.');
            $participants = array_map('intval', $data['participant_ids'] ?? []);
            abort_unless(in_array($user->id, $participants, true), 422, 'The representative must participate.');
            $valid = $group->members()->with('membership.user')->get()->pluck('membership.user_id')->all();
            abort_unless($participants && ! array_diff($participants, $valid), 422, 'Choose current group members.');
            foreach (User::whereIn('id', $participants)->get() as $participant) {
                abort_unless(OpportunityAccess::eligible($opportunity, $participant, $group->institution_id), 422, 'Every participating member must meet eligibility and access requirements.');
            }
        }
        $project = empty($data['project_id']) ? null : Project::findOrFail($data['project_id']);
        if ($project) {
            Gate::authorize('view', $project);
            abort_unless((! $project->institution_id || $project->institution_id === (int) $data['institution_id']) && (! $group || $project->group_id === $group->id), 422, 'Choose an authorised project matching the application context.');
        }
        $subject = $group ? 'group:'.$group->id : 'user:'.$user->id;

        return DB::transaction(function () use ($opportunity, $data, $application, $participants, $subject, $group, $project): Application {
            Opportunity::whereKey($opportunity->id)->lockForUpdate()->firstOrFail();
            if (! $application && Application::where('opportunity_id', $opportunity->id)->where('subject_key', $subject)->exists()) {
                throw ValidationException::withMessages(['motivation' => 'An application already exists for this applicant or group. Open it from My applications.']);
            }
            $signatureBefore = $application ? $this->consentSignature($application) : null;
            $application ??= new Application(['identifier' => (string) Str::ulid(), 'opportunity_id' => $opportunity->id, 'applicant_id' => auth()->id(), 'institution_id' => $data['institution_id'], 'group_id' => $group?->id, 'subject_key' => $subject, 'status' => 'draft']);
            $application->fill(collect($data)->only(['motivation', 'skills', 'project_summary'])->all());
            $application->project_id = $project?->id;
            $application->participant_ids = $participants;
            if (array_key_exists('career', $data)) {
                $application->career_selection = app(CareerMaterials::class)->capture(auth()->user(), $data['career'] ?? []);
            }
            $application->save();
            DB::table('opportunity_responses')->where('application_id', $application->id)->delete();
            $questions = DB::table('opportunity_questions')->where('opportunity_id', $opportunity->id)->get();
            abort_unless(! array_diff(array_keys($data['answers'] ?? []), $questions->pluck('id')->all()), 422, 'Answer only this opportunity’s questions.');
            foreach ($questions->where('type', '!=', 'document') as $question) {
                DB::table('opportunity_responses')->insert(['application_id' => $application->id, 'opportunity_id' => $opportunity->id, 'question_id' => $question->id, 'answer' => $data['answers'][$question->id] ?? null]);
            }
            if ($signatureBefore !== $this->consentSignature($application)) {
                DB::table('opportunity_consents')->where('application_id', $application->id)->delete();
                foreach ($participants as $id) {
                    DB::table('opportunity_consents')->insert(['application_id' => $application->id, 'user_id' => $id, 'consented_at' => null, 'created_at' => now(), 'updated_at' => now()]);
                }
                $this->inviteParticipants($application);
            }

            return $application;
        });
    }

    private function consentSignature(Application $application): string
    {
        $participants = $application->participant_ids ?? [];
        sort($participants);

        return hash('sha256', json_encode([$application->career_selection, $application->motivation, $application->skills ?: null, $application->project_summary ?: null, $application->project_id, $participants, DB::table('opportunity_responses')->where('application_id', $application->id)->orderBy('question_id')->pluck('answer', 'question_id')->all(), $application->documents()->orderBy('id')->pluck('id')->all()]));
    }

    private function inviteParticipants(Application $application): void
    {
        if (! $application->group_id) {
            return;
        }
        $key = 'consent:'.$application->id.':'.substr($this->consentSignature($application), 0, 40);
        foreach (array_diff($application->participant_ids ?? [], [auth()->id()]) as $userId) {
            if (DB::table('opportunity_notices')->insertOrIgnore(['opportunity_id' => $application->opportunity_id, 'user_id' => $userId, 'event_key' => $key, 'created_at' => now(), 'updated_at' => now()])) {
                OpportunitySignals::send([$userId], 'opportunity_application', $application, 'Group application consent requested', 'Your group representative requests fresh consent to the current application and documents.');
            }
        }
    }

    public function consent(Application $application, bool $consent): void
    {
        Gate::authorize('view', $application);
        abort_unless($application->group_id && in_array($application->status, ['draft', 'more_information_requested'], true), 422);
        $member = InstitutionAccess::membership($application->institution, auth()->user());
        abort_unless($member && $application->group->members()->where('membership_id', $member->id)->exists() && in_array(auth()->id(), $application->participant_ids ?? [], true), 403);
        DB::table('opportunity_consents')->where('application_id', $application->id)->where('user_id', auth()->id())->update(['consented_at' => $consent ? now() : null, 'updated_at' => now()]);
    }

    public function document(Application $application, array $data): void
    {
        Gate::authorize('update', $application);
        abort_unless(in_array($application->status, ['draft', 'more_information_requested'], true), 422);
        if (! empty($data['question_id'])) {
            abort_unless(DB::table('opportunity_questions')->where('opportunity_id', $application->opportunity_id)->where('type', 'document')->where('id', $data['question_id'])->exists(), 422);
        }
        $file = $data['file'];
        $path = $file->store('opportunity-documents', 'local');
        abort_unless(is_string($path) && $path !== '', 422, 'The supporting document could not be stored. Please try again.');
        try {
            DB::transaction(function () use ($application, $data, $file, $path): void {
                $application = Application::whereKey($application->id)->lockForUpdate()->firstOrFail();
                abort_unless(in_array($application->status, ['draft', 'more_information_requested'], true), 422);
                $application->documents()->create(['uploaded_by' => auth()->id(), 'question_id' => $data['question_id'] ?? null, 'path' => $path, 'name' => mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', basename($file->getClientOriginalName())), 0, 180), 'mime' => $file->getMimeType(), 'bytes' => $file->getSize(), 'version' => 0]);
                DB::table('opportunity_consents')->where('application_id', $application->id)->update(['consented_at' => null, 'updated_at' => now()]);
                $this->inviteParticipants($application);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    public function submit(Application $application): void
    {
        Gate::authorize('update', $application);
        DB::transaction(function () use ($application): void {
            $application = Application::with('opportunity', 'group')->whereKey($application->id)->lockForUpdate()->firstOrFail();
            $opportunity = $application->opportunity;
            if ($application->career_selection) {
                app(CareerMaterials::class)->validateCurrent($application->career_selection, auth()->user());
            }
            abort_unless(in_array($application->status, ['draft', 'more_information_requested'], true) && $opportunity->application_method === 'internal', 422);
            $users = User::whereIn('id', $application->participant_ids)->get();
            abort_unless($users->count() === count($application->participant_ids), 422);
            foreach ($users as $user) {
                abort_unless(OpportunityAccess::eligible($opportunity, $user, $application->institution_id), 422, 'Eligibility or audience access changed.');
            }
            if ($application->group_id) {
                $member = InstitutionAccess::membership($application->institution, auth()->user());
                abort_unless($application->group->status === 'active' && $application->group->members()->where('membership_id', $member?->id)->where('role', 'leader')->exists(), 403);
                $current = $application->group->members()->with('membership')->get()->pluck('membership.user_id')->all();
                abort_if(array_diff($application->participant_ids, $current), 422, 'Group membership changed. Update the draft and obtain fresh consent.');
                DB::table('opportunity_consents')->where('application_id', $application->id)->where('user_id', auth()->id())->update(['consented_at' => now()]);
                if (DB::table('opportunity_consents')->where('application_id', $application->id)->whereNotNull('consented_at')->count() !== $users->count()) {
                    throw ValidationException::withMessages(['consent' => 'Every participating member must consent to the current application and documents.']);
                }
            }
            if ($application->project_id) {
                Gate::authorize('view', $application->project);
            }
            $questions = DB::table('opportunity_questions')->where('opportunity_id', $opportunity->id)->orderBy('sort_order')->get();
            $responses = DB::table('opportunity_responses')->where('application_id', $application->id)->pluck('answer', 'question_id');
            foreach ($questions as $question) {
                $value = $responses[$question->id] ?? null;
                if ($question->type === 'document') {
                    if ($question->required && ! $application->documents()->where('question_id', $question->id)->exists()) {
                        throw ValidationException::withMessages(['file' => 'Upload every required supporting document.']);
                    }

                    continue;
                }
                if ($question->required && ($value === null || trim($value) === '')) {
                    throw ValidationException::withMessages(['answers' => 'Answer every required application question.']);
                }
                if ($value !== null && $value !== '') {
                    abort_if($question->type === 'number' && ! is_numeric($value), 422, 'A numeric response is required.');
                    abort_if($question->type === 'boolean' && ! in_array($value, ['yes', 'no'], true), 422, 'Choose yes or no.');
                    abort_if($question->type === 'select' && ! in_array($value, json_decode($question->options, true) ?? [], true), 422, 'Choose a configured answer.');
                }
            }
            $consents = DB::table('opportunity_consents')->where('application_id', $application->id)->pluck('consented_at', 'user_id');
            $snapshot = $users->map(function ($user) use ($application, $consents): array {
                $membership = InstitutionAccess::membership($application->institution, $user);
                $classes = DB::table('class_assignments as a')->join('academic_classes as c', 'c.id', '=', 'a.academic_class_id')->where('a.membership_id', $membership->id)->orderBy('c.id')->get(['c.id', 'c.programme_id', 'c.academic_year_id', 'c.intake_id'])->map(fn ($c) => (array) $c)->all();

                return ['user_id' => $user->id, 'name' => $user->name, 'membership_id' => $membership->id, 'classes' => $classes, 'consented_at' => $consents[$user->id] ?? ($user->id === auth()->id() ? now()->toIso8601String() : null)];
            })->all();
            $version = DB::table('application_snapshots')->where('application_id', $application->id)->max('version') + 1;
            DB::table('application_participants')->where('application_id', $application->id)->delete();
            foreach ($snapshot as $participant) {
                $programmeId = $application->group_id ? $application->group->academicClass->programme_id : ($participant['classes'][0]['programme_id'] ?? null);
                DB::table('application_participants')->insert(['application_id' => $application->id, 'user_id' => $participant['user_id'], 'institution_id' => $application->institution_id, 'programme_id' => $programmeId]);
            }
            $application->documents()->where('version', 0)->update(['version' => $version]);
            $payload = ['consent_policy_version' => 1, 'motivation' => $application->motivation, 'skills' => $application->skills, 'project_summary' => $application->project_summary, 'project_id' => $application->project_id, 'participants' => $snapshot, 'questions' => $questions->toArray(), 'responses' => $responses->all(), 'documents' => $application->documents()->get(['id', 'name', 'version', 'uploaded_by', 'created_at'])->toArray(), 'opportunity' => ['title' => $opportunity->title, 'eligibility_text' => $opportunity->eligibility_text, 'instructions' => $opportunity->instructions]];
            $payload['career_materials'] = app(CareerMaterials::class)->shared($application->career_selection ?? []);
            DB::table('application_snapshots')->insert(['application_id' => $application->id, 'version' => $version, 'payload' => Crypt::encryptString(json_encode($payload)), 'created_at' => now(), 'updated_at' => now()]);
            $application->update(['status' => 'submitted', 'participant_snapshot' => $snapshot, 'submitted_at' => now(), 'decided_at' => null]);
            Audit::record('opportunity_application.submitted', $application, $application->institution_id, ['version' => $version]);
            OpportunitySignals::application($application, true);
        });
    }

    public function external(Opportunity $opportunity, int $institutionId): Application
    {
        abort_unless($opportunity->application_method === 'external' && OpportunityAccess::eligible($opportunity, auth()->user(), $institutionId), 403);

        return Application::firstOrCreate(['opportunity_id' => $opportunity->id, 'subject_key' => 'user:'.auth()->id()], ['identifier' => (string) Str::ulid(), 'applicant_id' => auth()->id(), 'institution_id' => $institutionId, 'motivation' => 'Self-reported external application. Not verified by Ideyra.', 'status' => 'external_reported', 'participant_ids' => [auth()->id()], 'external_self_reported' => true]);
    }

    public function withdraw(Application $application): void
    {
        Gate::authorize('update', $application);
        abort_unless(in_array($application->status, ['draft', 'submitted', 'under_review', 'more_information_requested', 'shortlisted', 'external_reported'], true), 422);
        $application->update(['status' => 'withdrawn']);
        Audit::record('opportunity_application.withdrawn', $application, $application->institution_id);
    }

    public function review(Application $application, string $status, string $feedback, ?string $note): void
    {
        Gate::authorize('review', $application);
        DB::transaction(function () use ($application, $status, $feedback, $note): void {
            $application = Application::whereKey($application->id)->lockForUpdate()->firstOrFail();
            $allowed = ['submitted' => ['under_review', 'more_information_requested', 'shortlisted', 'accepted', 'rejected'], 'under_review' => ['more_information_requested', 'shortlisted', 'accepted', 'rejected'], 'shortlisted' => ['more_information_requested', 'accepted', 'rejected']];
            abort_unless(in_array($status, $allowed[$application->status] ?? [], true), 422, 'This review transition is not permitted.');
            if ($status === 'accepted' && $application->opportunity->places) {
                Opportunity::whereKey($application->opportunity_id)->lockForUpdate()->firstOrFail();
                $ids = $application->opportunity->applications()->where('status', 'accepted')->pluck('participant_ids')->flatten()->merge($application->participant_ids)->unique();
                abort_if($ids->count() > $application->opportunity->places, 422, 'The configured available places would be exceeded.');
            }
            $application->reviews()->create(['reviewer_id' => auth()->id(), 'decision' => $status, 'feedback' => $feedback, 'internal_note' => $note]);
            $application->update(['status' => $status, 'decided_at' => in_array($status, ['accepted', 'rejected'], true) ? now() : null]);
            Audit::record('opportunity_application.reviewed', $application, $application->institution_id, ['status' => $status]);
            OpportunitySignals::application($application);
        });
    }
}

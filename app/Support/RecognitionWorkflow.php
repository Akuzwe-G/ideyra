<?php

namespace App\Support;

use App\Models\AchievementVerification;
use App\Models\CollaborationWorkspace;
use App\Models\InnovationChallenge;
use App\Models\InnovationEvent;
use App\Models\Institution;
use App\Models\NetworkRecognition;
use App\Models\ProfessionalEntry;
use App\Models\ProfessionalProfile;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecognitionWorkflow
{
    public function confirm(User $user, array $data): NetworkRecognition
    {
        $institution = Institution::findOrFail($data['institution_id']);
        abort_unless(NetworkAccess::can($user, $institution, 'network.achievements_confirm'), 403);
        $recipient = User::whereKey($data['user_id'])->where('status', 'active')->firstOrFail();
        abort_if($recipient->id === $user->id, 403, 'Recognition must be independently confirmed.');
        $type = $data['source_type'];
        $id = (int) $data['source_id'];
        if ($type === 'challenge') {
            $source = InnovationChallenge::findOrFail($id);
            abort_unless($source->institution_id === $institution->id && NetworkAccess::challengeManage($user, $source) && $source->status === 'results_published', 403);
            $application = $source->opportunity->applications()->where('status', 'submitted')->whereJsonContains('participant_ids', $recipient->id)->first();
            abort_unless($application, 422, 'Only recorded participating students are eligible.');
            abort_unless(collect($source->results_snapshot['entries'] ?? [])->contains(fn ($row) => $source->submissions()->whereKey($row['submission_id'])->where('opportunity_application_id', $application->id)->exists()), 422, 'This participant has no published final challenge result.');
        } elseif ($type === 'event') {
            $source = InnovationEvent::findOrFail($id);
            abort_unless($source->institution_id === $institution->id && NetworkAccess::eventManage($user, $source) && $source->status === 'completed' && $source->registrations()->where('user_id', $recipient->id)->where('status', 'attended')->exists(), 403, 'Recorded attendance at a completed event is required.');
        } elseif ($type === 'collaboration') {
            $source = CollaborationWorkspace::findOrFail($id);
            abort_unless(in_array($institution->id, $source->institution_ids, true) && $source->status === 'closed' && NetworkAccess::member($user, $source, true) && NetworkAccess::member($recipient, $source), 403);
            abort_unless($source->entries()->where('assigned_to', $recipient->id)->whereIn('kind', ['task', 'milestone'])->where('status', 'completed')->whereNull('removed_at')->exists(), 422, 'Confirm a recorded contribution, not membership alone.');
        } else {
            abort(422);
        }

        return DB::transaction(function () use ($user, $institution, $recipient, $data, $type, $id): NetworkRecognition {
            $existing = NetworkRecognition::where('source_type', $type)->where('source_id', $id)->where('user_id', $recipient->id)->first();
            abort_if($existing, 422, 'A recognition already exists for this participant and source; review that record instead.');
            $profile = ProfessionalProfile::firstOrCreate(['user_id' => $recipient->id], ['display_name' => $recipient->name]);
            $entry = $profile->entries()->create(['type' => 'achievement', 'title' => $data['title'], 'category' => 'Innovation', 'content' => ['organisation' => $institution->name, 'start' => today(config('intelligence.timezone'))->toDateString(), 'description' => 'Institution-confirmed '.$type.' recognition. Ownership and licensing are not determined by this recognition.'], 'sort_order' => 0]);
            $subject = app(CareerWorkflow::class)->entryPayload($entry);
            $verification = AchievementVerification::create(['reference' => (string) Str::uuid(), 'user_id' => $recipient->id, 'institution_id' => $institution->id, 'professional_entry_id' => $entry->id, 'category' => 'achievement', 'subject_key' => 'entry:'.$entry->id, 'title' => $entry->title, 'status' => 'verified', 'evidence' => ['statement' => $data['evidence'], 'subject' => $subject, 'network_source' => ['type' => $type, 'id' => $id]], 'approved_content' => $subject, 'verified_by' => $user->id, 'verified_at' => now(), 'public_allowed' => false]);
            $recognition = NetworkRecognition::create(['institution_id' => $institution->id, 'user_id' => $recipient->id, 'confirmed_by' => $user->id, 'source_type' => $type, 'source_id' => $id, 'title' => $data['title'], 'evidence' => $data['evidence'], 'professional_entry_id' => $entry->id, 'achievement_verification_id' => $verification->id, 'status' => 'confirmed', 'confirmed_at' => now()]);
            DB::table('verification_decisions')->insert(['achievement_verification_id' => $verification->id, 'reviewer_id' => $user->id, 'status' => 'verified', 'reason' => Crypt::encryptString('Explicit innovation recognition confirmation'), 'evidence_reviewed' => Crypt::encryptString($data['evidence']), 'created_at' => now(), 'updated_at' => now()]);
            NetworkSignals::record('recognition.confirmed', $recognition, $institution->id, ['source_type' => $type, 'source_id' => $id]);
            NetworkSignals::send([$recipient->id], 'network_recognition', $recognition, 'confirmed', 'An organiser confirmed an innovation achievement');

            return $recognition;
        });
    }

    public function visibility(User $user, NetworkRecognition $recognition, bool $public): void
    {
        abort_unless($recognition->user_id === $user->id && $user->status === 'active' && $recognition->status === 'confirmed' && $recognition->verification->status === 'verified', 403);
        $entry = ProfessionalEntry::findOrFail($recognition->professional_entry_id);
        abort_unless($recognition->verification->approved_content === app(CareerWorkflow::class)->entryPayload($entry), 422, 'The achievement entry changed since confirmation.');
        $recognition->verification->update(['public_allowed' => $public]);
        NetworkSignals::record('recognition.visibility_changed', $recognition, $recognition->institution_id, ['public_allowed' => $public]);
    }

    public function revoke(User $user, NetworkRecognition $recognition, string $reason): void
    {
        abort_unless(NetworkAccess::can($user, $recognition->institution, 'network.achievements_confirm') && $recognition->status === 'confirmed' && $recognition->user_id !== $user->id, 403);
        DB::transaction(function () use ($recognition, $reason): void {
            $recognition->update(['status' => 'revoked']);
            $recognition->verification->update(['status' => 'revoked', 'revoked_at' => now(), 'public_allowed' => false]);
            NetworkSignals::record('recognition.revoked',$recognition,$recognition->institution_id,['reason' => $reason]);
        });
    }
}

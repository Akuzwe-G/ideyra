<?php

namespace App\Http\Controllers;

use App\Models\AchievementVerification;
use App\Models\Institution;
use App\Models\User;
use App\Support\Audit;
use App\Support\CareerAccess;
use App\Support\CareerWorkflow;
use App\Support\InnovationAccess;
use App\Support\InstitutionAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VerificationController extends Controller
{
    public function index(Request $request, CareerWorkflow $workflow): View
    {
        $profile = $workflow->profile();
        $memberships = InnovationAccess::memberships($request->user());
        $own = AchievementVerification::where('user_id', $request->user()->id)->with('institution')->latest()->paginate(12, ['*'], 'own_page');
        $reviewMemberships = $memberships->filter(fn ($m) => InnovationAccess::can($m->institution, 'career.verify', $request->user()) && in_array($m->role->name, ['Institution Administrator', 'Lecturer / Teacher', 'Mentor / Supervisor'], true));
        $administratorIds = $reviewMemberships->where('role.name', 'Institution Administrator')->pluck('institution_id');
        $institutionIds = $reviewMemberships->pluck('institution_id');
        $assigned = InnovationAccess::supervised($request->user())->select('projects.id');
        $queue = AchievementVerification::where('user_id', '!=', $request->user()->id)
            ->where(fn ($q) => $q->whereIn('institution_id', $administratorIds)->orWhere(fn ($q) => $q->whereIn('institution_id', $institutionIds)->whereHas('showcase', fn ($s) => $s->whereIn('project_id', $assigned))))
            ->with('institution', 'user', 'showcase.project', 'entry')->latest()->paginate(12, ['*'], 'review_page');
        $queue->setCollection($queue->getCollection()->filter(fn ($v) => CareerAccess::canReview($v, $request->user())));

        return view('career.verifications', compact('profile', 'memberships', 'own', 'queue'));
    }

    public function request(Request $request, CareerWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['institution_id' => 'required|integer|exists:institutions,id', 'entry_id' => 'nullable|integer', 'showcase_id' => 'nullable|integer', 'category' => ['required', Rule::in(array_keys(config('career.verification_categories')))], 'evidence' => 'required|string|max:6000', 'public_allowed' => 'nullable|boolean', 'confirm' => 'accepted']);
        $institution = Institution::findOrFail($data['institution_id']);
        abort_unless(InstitutionAccess::membership($institution, $request->user()), 403);
        abort_unless((bool) ($data['entry_id'] ?? null) !== (bool) ($data['showcase_id'] ?? null), 422, 'Select one entry or one showcased project.');
        $profile = $workflow->profile();
        $entry = empty($data['entry_id']) ? null : $profile->entries()->findOrFail($data['entry_id']);
        $showcase = empty($data['showcase_id']) ? null : $profile->showcases()->findOrFail($data['showcase_id']);
        $subject = $entry ? $workflow->entryPayload($entry) : CareerAccess::projectContent($showcase);
        if ($entry) {
            $allowed = match ($entry->type) {
                'achievement' => ['achievement', 'award'],'certification' => ['certificate'],'skill' => ['skill'],default => []
            };
            abort_unless(in_array($data['category'], $allowed, true), 422);
        } else {
            abort_unless($subject && $showcase->project->institution_id === $institution->id && in_array($data['category'], ['academic_project', 'leadership', 'participation'], true), 422);
            abort_if($data['category'] === 'leadership' && ! in_array($subject['role'], ['Project leader', 'Project creator'], true), 422);
            abort_if($data['category'] === 'participation' && $subject['role'] === 'Supervisor', 422);
        }
        DB::transaction(function () use ($request, $data, $institution, $entry, $showcase, $subject, $workflow): void {
            $key = $entry ? 'entry:'.$entry->id : 'showcase:'.$showcase->id;
            $existing = AchievementVerification::where('user_id', $request->user()->id)->where('institution_id', $institution->id)->where('subject_key', $key)->where('category', $data['category'])->lockForUpdate()->first();
            abort_if($existing && in_array($existing->status, ['requested', 'under_review'], true), 422, 'This verification request is already awaiting review.');
            $filePath = null;
            if ($entry?->evidence_path && Storage::disk('local')->exists($entry->evidence_path)) {
                $filePath = 'verification-evidence/'.Str::uuid().'.'.pathinfo($entry->evidence_path, PATHINFO_EXTENSION);
                Storage::disk('local')->copy($entry->evidence_path, $filePath);
            }
            if ($existing) {
                DB::table('verification_decisions')->insert(['achievement_verification_id' => $existing->id, 'reviewer_id' => $request->user()->id, 'status' => 'requested',
                    'reason' => Crypt::encryptString('The owner requested a fresh evidence review; this is not self-verification.'),
                    'evidence_reviewed' => Crypt::encryptString(json_encode(['previous_evidence' => $existing->evidence, 'previous_approved_content' => $existing->approved_content, 'previous_status' => $existing->status])),
                    'created_at' => now(), 'updated_at' => now()]);
            }
            $values = ['reference' => (string) Str::uuid(), 'user_id' => $request->user()->id, 'institution_id' => $institution->id,
                'professional_entry_id' => $entry?->id, 'project_showcase_id' => $showcase?->id, 'subject_key' => $key, 'category' => $data['category'], 'title' => $subject['title'],
                'public_allowed' => $data['public_allowed'] ?? false,
                'evidence' => ['statement' => $data['evidence'], 'subject' => $subject, 'file_path' => $filePath, 'file_name' => $entry?->evidence_name, 'sha256' => $filePath ? hash_file('sha256', Storage::disk('local')->path($filePath)) : null],
                'status' => 'requested', 'approved_content' => null, 'verified_by' => null, 'verified_at' => null, 'revoked_at' => null];
            $verification = $existing ?? new AchievementVerification;
            $verification->fill($values)->save();
            Audit::record('career.verification_requested', $verification, $institution->id);
            foreach (User::whereHas('memberships', fn ($q) => $q->where('institution_id', $institution->id)->where('status', 'active'))->get() as $candidate) {
                if (CareerAccess::canReview($verification, $candidate)) {
                    $workflow->notify($candidate, 'Achievement verification requested', 'An authorised verification request is awaiting review.');
                }
            }
        });

        return redirect()->route('career.verifications')->with('status', 'Verification requested. Uploads are not automatically verified.');
    }

    public function decision(Request $request, AchievementVerification $verification, CareerWorkflow $workflow): RedirectResponse
    {
        abort_unless(CareerAccess::canReview($verification, $request->user()), 403);
        $data = $request->validate(['status' => 'required|in:under_review,verified,rejected,revoked', 'reason' => 'required|string|max:6000', 'evidence_reviewed' => 'required|string|max:6000', 'public_allowed' => 'nullable|boolean']);
        DB::transaction(function () use ($request, $verification, $data, $workflow): void {
            $verification = AchievementVerification::whereKey($verification->id)->lockForUpdate()->firstOrFail();
            $transitions = ['requested' => ['under_review', 'verified', 'rejected'], 'under_review' => ['verified', 'rejected'], 'verified' => ['revoked'], 'rejected' => ['under_review'], 'revoked' => ['under_review']];
            abort_unless(in_array($data['status'], $transitions[$verification->status] ?? [], true), 422);
            $current = $verification->entry ? $workflow->entryPayload($verification->entry) : CareerAccess::projectContent($verification->showcase);
            if ($data['status'] === 'verified') {
                abort_unless($current && $current === $verification->evidence['subject'], 422, 'The subject has changed since evidence was submitted. Reject this request; review the original evidence history.');
            }
            DB::table('verification_decisions')->insert(['achievement_verification_id' => $verification->id, 'reviewer_id' => $request->user()->id, 'status' => $data['status'],
                'reason' => Crypt::encryptString($data['reason']), 'evidence_reviewed' => Crypt::encryptString($data['evidence_reviewed']), 'created_at' => now(), 'updated_at' => now()]);
            $values = ['status' => $data['status']];
            if ($data['status'] === 'verified') {
                $values += ['verified_by' => $request->user()->id, 'verified_at' => now(), 'revoked_at' => null, 'approved_content' => $current,
                    'public_allowed' => $verification->public_allowed && ($data['public_allowed'] ?? false)];
            }
            if ($data['status'] === 'revoked') {
                $values['revoked_at'] = now();
            }
            $verification->update($values);
            Audit::record('career.verification_'.$data['status'], $verification, $verification->institution_id);
            $workflow->notify($verification->user, 'Verification '.$data['status'], 'A verification decision has been recorded. Review it in Career & Profile.');
        });

        return redirect()->route('career.verifications')->with('status', 'Verification decision recorded with evidence and decision history.');
    }

    public function visibility(Request $request, AchievementVerification $verification): RedirectResponse
    {
        abort_unless(CareerAccess::own($request->user(), $verification->user_id), 403);
        $request->validate(['hide' => 'accepted']);
        $verification->update(['public_allowed' => false]);
        Audit::record('career.verification_reference_hidden', $verification, $verification->institution_id);

        return redirect()->route('career.verifications')->with('status', 'Public verification reference hidden.');
    }

    public function evidence(AchievementVerification $verification): StreamedResponse
    {
        abort_unless(CareerAccess::own(auth()->user(), $verification->user_id) || CareerAccess::canReview($verification, auth()->user()), 403);
        $path = $verification->evidence['file_path'] ?? null;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, $verification->evidence['file_name'] ?? 'evidence', ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function publicReference(string $reference): Response
    {
        $verification = AchievementVerification::where('reference', $reference)->firstOrFail();
        abort_unless(CareerAccess::publicVerification($verification), 404);

        return response()->view('career.verification-public', compact('verification'))->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'private, no-store');
    }
}

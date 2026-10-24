<?php

namespace App\Support;

use App\Models\AchievementVerification;
use App\Models\CvDocument;
use App\Models\ProfessionalProfile;
use App\Models\ProjectShowcase;
use App\Models\User;

class CareerMaterials
{
    public function capture(User $user, array $selection): array
    {
        $profile = ProfessionalProfile::where('user_id', $user->id)->first();
        $result = ['owner_id' => $user->id, 'profile' => [], 'achievements' => [], 'projects' => []];
        if (! empty($selection['cv_id'])) {
            $cv = CvDocument::where('user_id', $user->id)->findOrFail($selection['cv_id']);
            $result['cv'] = $cv->only(['title', 'template', 'sections', 'content']);
            $result['cv']['source_id'] = $cv->id;
            $result['cv']['source_version'] = $cv->snapshots()->max('version');
        }
        if (! empty($selection['profile_fields'])) {
            abort_unless($profile, 422);
            $result['profile'] = $profile->only($selection['profile_fields']);
        }
        foreach ($selection['achievement_ids'] ?? [] as $id) {
            $v = AchievementVerification::where('user_id', $user->id)->where('status', 'verified')->findOrFail($id);
            abort_unless($v->institution->status === 'active' && $v->entry && $v->approved_content === app(CareerWorkflow::class)->entryPayload($v->entry), 422, 'Selected achievement verification is no longer current.');
            $result['achievements'][] = ['verification_id' => $v->id, 'title' => $v->title, 'category' => $v->category, 'institution' => $v->institution->name, 'verified_at' => $v->verified_at->toIso8601String()];
        }
        foreach ($selection['showcase_ids'] ?? [] as $id) {
            $s = ProjectShowcase::whereHas('profile', fn ($q) => $q->where('user_id', $user->id))->findOrFail($id);
            $payload = CareerAccess::publicProject($s);
            abort_unless($payload, 422, 'Restricted projects require explicit institutional sharing approval.');
            $result['projects'][] = $payload;
        }

        return $this->shared($result);
    }

    public function shared(array $materials): array
    {
        if (isset($materials['cv'])) {
            $materials['cv']['content'] = array_intersect_key($materials['cv']['content'], array_fill_keys(['contact', ...$materials['cv']['sections']], true));
        }

        return $materials;
    }

    public function validateCurrent(array $materials, User $user): void
    {
        abort_unless(($materials['owner_id'] ?? null) === $user->id, 403);
        foreach ($materials['projects'] ?? [] as $expected) {
            $showcase = ProjectShowcase::whereHas('profile', fn ($q) => $q->where('user_id', $user->id))->find($expected['showcase_id']);
            abort_unless($showcase && CareerAccess::publicProject($showcase, $expected), 422, 'A selected showcase changed or lost permission. Update your career material selection.');
        }
        foreach ($materials['achievements'] ?? [] as $expected) {
            $v = AchievementVerification::where('user_id', $user->id)->find($expected['verification_id']);
            abort_unless($v && $v->status === 'verified' && $v->institution->status === 'active' && $v->entry && $v->approved_content === app(CareerWorkflow::class)->entryPayload($v->entry), 422, 'A selected achievement verification changed. Update your material selection.');
        }
    }
}

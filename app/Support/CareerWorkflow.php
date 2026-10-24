<?php

namespace App\Support;

use App\Models\AchievementVerification;
use App\Models\CvDocument;
use App\Models\Portfolio;
use App\Models\PortfolioMedia;
use App\Models\ProfessionalEntry;
use App\Models\ProfessionalProfile;
use App\Models\User;
use App\Notifications\WorkspaceNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CareerWorkflow
{
    public function storeImage(UploadedFile $file, string $directory): string
    {
        if (! function_exists('imagecreatefromstring')) {
            $path = $file->store($directory, 'local');
            abort_unless(is_string($path) && $path !== '', 422, 'Image storage failed.');

            return $path;
        }
        $source = @imagecreatefromstring($file->get());
        abort_unless($source, 422, 'This image cannot be decoded safely.');
        $ratio = min(1, 1600 / max(imagesx($source), imagesy($source)));
        $image = imagecreatetruecolor(max(1, (int) round(imagesx($source) * $ratio)), max(1, (int) round(imagesy($source) * $ratio)));
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagecopyresampled($image, $source, 0, 0, 0, 0, imagesx($image), imagesy($image), imagesx($source), imagesy($source));
        ob_start();
        imagepng($image, null, 6);
        $bytes = ob_get_clean();
        imagedestroy($source);
        imagedestroy($image);
        $path = $directory.'/'.Str::uuid().'.png';
        abort_unless(is_string($bytes) && Storage::disk('local')->put($path, $bytes), 422, 'Image storage failed.');

        return $path;
    }

    public function profile(): ProfessionalProfile
    {
        return ProfessionalProfile::firstOrCreate(['user_id' => auth()->id()], ['display_name' => auth()->user()->name]);
    }

    public function saveProfile(array $data): ProfessionalProfile
    {
        $profile = $this->profile();
        Gate::authorize('update', $profile);
        $old = $profile->photo_path;
        if (! empty($data['photo'])) {
            $data['photo_path'] = $this->storeImage($data['photo'], 'career-photos');
        } elseif (! empty($data['remove_photo'])) {
            $data['photo_path'] = null;
        }
        $profile->update(collect($data)->except(['photo', 'remove_photo'])->all());
        if ($old && $old !== $profile->photo_path) {
            Storage::disk('local')->delete($old);
        }
        Audit::record('career.profile_updated', $profile);

        return $profile;
    }

    public function saveEntry(array $data, ?ProfessionalEntry $entry = null): ProfessionalEntry
    {
        $profile = $this->profile();
        if ($entry) {
            Gate::authorize('update', $entry->profile);
            abort_unless($entry->type === $data['type'], 422);
        }
        $old = $entry?->evidence_path;
        if (! empty($data['evidence'])) {
            $data['evidence_path'] = $data['evidence']->store('career-evidence', 'local');
            $data['evidence_name'] = Str::limit(basename($data['evidence']->getClientOriginalName()), 180, '');
        } elseif (! empty($data['remove_evidence'])) {
            $data['evidence_path'] = $data['evidence_name'] = null;
        }
        $fields = collect($data)->except(['evidence', 'remove_evidence'])->all();
        $entry ??= new ProfessionalEntry(['professional_profile_id' => $profile->id]);
        $entry->fill($fields)->save();
        if ($old && $old !== $entry->evidence_path) {
            Storage::disk('local')->delete($old);
        }
        Audit::record('career.entry_saved', $entry);

        return $entry;
    }

    public function entryPayload(ProfessionalEntry $entry): array
    {
        return ['id' => $entry->id, 'type' => $entry->type, 'title' => $entry->title, 'category' => $entry->category, 'content' => $entry->content, 'sort_order' => $entry->sort_order];
    }

    /** @return array{percent:int,suggestions:list<string>} */
    public function completeness(ProfessionalProfile $profile): array
    {
        $types = $profile->entries()->pluck('type')->all();
        $checks = ['Add your professional display name' => (bool) $profile->display_name, 'Add your professional headline' => (bool) $profile->headline,
            'Add a short biography' => (bool) $profile->biography, 'Add your education history' => in_array('education', $types, true),
            'Add your first skill' => in_array('skill', $types, true),
            'Showcase a completed project' => $profile->showcases()->whereHas('project', fn ($q) => $q->where('status', 'completed'))->get()->contains(fn ($s) => CareerAccess::projectContent($s) !== null),
            'Add work or engagement experience' => in_array('experience', $types, true),
            'Record an achievement or certification' => (bool) array_intersect(['achievement', 'certification'], $types)];

        return ['percent' => (int) round(count(array_filter($checks)) / 8 * 100), 'suggestions' => array_keys(array_filter($checks, fn ($done) => ! $done))];
    }

    public function importedContent(ProfessionalProfile $profile): array
    {
        $content = array_fill_keys(array_keys(config('career.sections')), '');
        $content['contact'] = implode("\n", array_filter([$profile->display_name, $profile->headline, $profile->professional_email, $profile->phone, $profile->location, ...array_values($profile->links ?? [])]));
        $content['summary'] = $profile->biography ?? '';
        foreach ($profile->entries as $entry) {
            $content[$entry->type] .= trim($entry->title."\n".implode("\n", array_filter($entry->content, fn ($v) => is_string($v) && $v !== '')))."\n\n";
        }
        foreach ($profile->showcases as $showcase) {
            if ($project = CareerAccess::publicProject($showcase)) {
                $content['project'] .= $project['title'].' — '.$project['role']."\n".$project['description']."\n".$project['technologies']."\n".implode("\n", $project['links'])."\n\n";
            }
        }

        return array_map('trim', $content);
    }

    public function saveCv(array $data, ?CvDocument $cv = null): CvDocument
    {
        if ($cv) {
            abort_unless(CareerAccess::own(auth()->user(), $cv->user_id), 403);
        }

        return DB::transaction(function () use ($data, $cv): CvDocument {
            if ($cv) {
                $cv = CvDocument::whereKey($cv->id)->lockForUpdate()->firstOrFail();
            }
            $cv ??= new CvDocument(['user_id' => auth()->id()]);
            $cv->fill(collect($data)->only(['title', 'template', 'sections', 'content'])->all())->save();
            $cv->snapshots()->create(['version' => ($cv->snapshots()->max('version') ?? 0) + 1, 'payload' => ['title' => $cv->title, 'template' => $cv->template, 'sections' => $cv->sections, 'content' => $cv->content]]);
            Audit::record('career.cv_saved', $cv);

            return $cv;
        });
    }

    public function portfolio(): Portfolio
    {
        $profile = $this->profile();

        return Portfolio::firstOrCreate(['professional_profile_id' => $profile->id], ['slug' => 'member-'.strtolower((string) Str::ulid()), 'sections' => ['biography', 'skill', 'project'], 'entry_ids' => [], 'showcase_ids' => [], 'contact_fields' => []]);
    }

    public function portfolioPayload(Portfolio $portfolio): array
    {
        $profile = $portfolio->profile;
        $entries = $profile->entries()->whereIn('id', $portfolio->entry_ids ?? [])->whereIn('type', $portfolio->sections ?? [])->get();
        $projects = in_array('project', $portfolio->sections ?? [], true) ? $profile->showcases()->whereIn('id', $portfolio->showcase_ids ?? [])->orderBy('sort_order')->orderBy('id')->get()->map(fn ($s) => CareerAccess::publicProject($s))->filter()->values()->all() : [];
        $verifications = AchievementVerification::where('user_id', $profile->user_id)->where('status', 'verified')->where('public_allowed', true)->get()->filter(function ($v) use ($entries, $projects): bool {
            return ($v->professional_entry_id && $entries->contains(fn ($entry) => $entry->id === $v->professional_entry_id && $v->approved_content === $this->entryPayload($entry)))
                || ($v->project_showcase_id && collect($projects)->contains(fn ($p) => $p['showcase_id'] === $v->project_showcase_id));
        })->map(fn ($v) => ['reference' => $v->reference, 'title' => $v->title])->values()->all();
        $contact = collect($profile->only(['professional_email', 'phone', 'location', 'links', 'photo_path']))->filter(fn ($value, $key) => in_array($key === 'photo_path' ? 'photo' : $key, $portfolio->contact_fields ?? [], true))->all();
        $mediaIds = PortfolioMedia::whereIn('project_showcase_id', collect($projects)->pluck('showcase_id'))->where('public_approved', true)->pluck('id')->all();

        return ['media_ids' => $mediaIds, 'display_name' => $profile->display_name, 'headline' => $profile->headline, 'biography' => $profile->biography, 'contact' => $contact,
            'entries' => $entries->map(fn ($e) => $this->entryPayload($e))->all(), 'projects' => $projects, 'verifications' => $verifications];
    }

    public function visiblePayload(Portfolio $portfolio): array
    {
        $payload = $portfolio->payload ?? [];
        $entryIds = $portfolio->profile->entries()->whereIn('id', collect($payload['entries'] ?? [])->pluck('id'))->pluck('id')->all();
        $payload['entries'] = array_values(array_filter($payload['entries'] ?? [], fn ($e) => in_array($e['id'], $entryIds, true)));
        $showcases = $portfolio->profile->showcases()->with('profile.user', 'project.institution', 'verifications')->whereIn('id', collect($payload['projects'] ?? [])->pluck('showcase_id'))->get()->keyBy('id');
        $payload['projects'] = array_values(array_filter($payload['projects'] ?? [], function ($expected) use ($showcases): bool {
            $showcase = $showcases->get($expected['showcase_id']);

            return $showcase && CareerAccess::publicProject($showcase, $expected) !== null;
        }));
        if (($payload['contact']['photo_path'] ?? null) !== $portfolio->profile->photo_path) {
            unset($payload['contact']['photo_path']);
        }
        $verifications = AchievementVerification::with('institution')->whereIn('reference', collect($payload['verifications'] ?? [])->pluck('reference'))->get()->keyBy('reference');
        $payload['verifications'] = array_values(array_filter($payload['verifications'] ?? [], function ($v) use ($payload, $verifications): bool {
            $verification = $verifications->get($v['reference']);
            if (! $verification || ! $verification->public_allowed || $verification->institution->status !== 'active') {
                return false;
            }

            return $verification->professional_entry_id
                ? collect($payload['entries'])->contains(fn ($e) => $e['id'] === $verification->professional_entry_id && $verification->approved_content === $e)
                : collect($payload['projects'])->contains(fn ($p) => $p['showcase_id'] === $verification->project_showcase_id);
        }));

        return $payload;
    }

    public function publish(Portfolio $portfolio, bool $publish): void
    {
        Gate::authorize('update', $portfolio->profile);
        DB::transaction(function () use ($portfolio, $publish): void {
            $portfolio = Portfolio::whereKey($portfolio->id)->lockForUpdate()->firstOrFail();
            if ($publish) {
                $portfolio->payload = $this->portfolioPayload($portfolio);
            }
            $changed = $portfolio->published !== $publish;
            $portfolio->published = $publish;
            $portfolio->published_at = $publish ? now() : null;
            $portfolio->save();
            if ($changed) {
                DB::table('career_publication_events')->insert(['portfolio_id' => $portfolio->id, 'published' => $publish, 'created_at' => now()]);
                $this->notify($portfolio->profile->user, 'Portfolio publication changed', 'Your portfolio is now '.($publish ? 'published.' : 'unpublished.'));
            }
            Audit::record($publish ? 'career.portfolio_published' : 'career.portfolio_unpublished', $portfolio);
        });
    }

    public function notify(User $user, string $title, string $message): void
    {
        if ($user->status === 'active') {
            $user->notifyNow(new WorkspaceNotification(['title' => $title, 'message' => $message, 'target' => 'career', 'subject_id' => $user->id]));
        }
    }
}

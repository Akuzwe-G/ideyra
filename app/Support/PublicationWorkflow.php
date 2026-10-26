<?php

namespace App\Support;

use App\Models\Institution;
use App\Models\NetworkPublication;
use App\Models\Project;
use App\Models\PublicationMedia;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PublicationWorkflow
{
    public function save(User $user, array $data, ?NetworkPublication $publication = null): NetworkPublication
    {
        $project = Project::findOrFail($data['project_id']);
        $institution = Institution::findOrFail($data['institution_id']);
        abort_unless(NetworkAccess::representative($project, $user) && NetworkAccess::can($user, $institution, 'showcase.submit'), 403);
        abort_if($project->institution_id && $project->institution_id !== $institution->id, 422, 'Use the project owning institution.');
        abort_unless(NetworkAccess::publicScopeAllowed($institution, $data['visibility']), 422, 'Institution policy does not permit that publication audience.');
        $ids = array_values(array_unique(array_map('intval', $data['attribution_ids'] ?? [])));
        abort_if(array_diff($ids, NetworkAccess::contributors($project)), 422, 'Only current project contributors may be named.');
        $draft = collect($data)->only(['title', 'summary', 'description', 'outcomes', 'problem', 'solution', 'objectives', 'category', 'technologies', 'demo_links', 'interests', 'declared_owner', 'licence', 'restrictions', 'attribution_requirements'])->all();
        $draft['technologies'] = array_values(array_unique($draft['technologies'] ?? []));
        $draft['demo_links'] = $draft['demo_links'] ?? [];
        $draft['interests'] = $draft['interests'] ?? [];
        $draft['acknowledgements'] = ['ip' => true, 'publication' => true, 'by' => $user->id, 'at' => now()->toIso8601String()];

        return DB::transaction(function () use ($publication, $project, $institution, $user, $data, $ids, $draft): NetworkPublication {
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            if ($publication) {
                $publication = NetworkPublication::whereKey($publication->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($user)->authorize('update', $publication);
                abort_unless($publication->project_id === $project->id && $publication->institution_id === $institution->id, 422, 'The publication source and institution cannot be transferred.');
                abort_unless($publication->version === (int) $data['version'], 409, 'Reload the latest publication before editing.');
                abort_unless(in_array($publication->status, ['draft', 'returned', 'rejected', 'unpublished'], true), 422, 'Unpublish or complete review before editing.');
                $publication->version++;
            } else {
                abort_if(NetworkPublication::where('project_id', $project->id)->exists(), 422, 'This project already has a showcase submission. Open that publication to continue.');
                $publication = new NetworkPublication(['identifier' => (string) Str::ulid(), 'project_id' => $project->id, 'institution_id' => $institution->id, 'version' => 1]);
            }
            $publication->fill(['submitted_by' => $user->id, 'title' => $data['title'], 'summary' => $data['summary'], 'category' => $data['category'], 'project_status' => $project->status, 'draft' => $draft, 'visibility' => $data['visibility'], 'institution_attribution' => (bool) ($data['institution_attribution'] ?? false), 'collaboration_open' => (bool) ($data['collaboration_open'] ?? false), 'status' => 'draft', 'approved_by' => null, 'approved_version' => null, 'approved_snapshot' => null, 'published_at' => null]);
            $publication->save();
            $publication->consents()->delete();
            foreach ($ids as $id) {
                $publication->consents()->create(['user_id' => $id, 'version' => $publication->version, 'consented_at' => $id === $user->id ? now() : null]);
            }
            NetworkSignals::record('publication.draft_saved', $publication, $institution->id, ['version' => $publication->version]);
            NetworkSignals::send(array_diff($ids, [$user->id]), 'network_publication', $publication, 'consent:'.$publication->version, 'Publication attribution consent requested');

            return $publication;
        });
    }

    public function consent(User $user, NetworkPublication $publication, bool $consent): void
    {
        abort_unless($user->status === 'active' && in_array($user->id, NetworkAccess::contributors($publication->project), true), 403);
        $row = $publication->consents()->where('user_id', $user->id)->where('version', $publication->version)->firstOrFail();
        $row->update(['consented_at' => $consent ? now() : null]);
        NetworkSignals::record($consent ? 'publication.consent_granted' : 'publication.consent_revoked', $publication, $publication->institution_id, ['version' => $publication->version, 'user_id' => $user->id]);
    }

    public function transition(User $user, NetworkPublication $publication, string $status, string $reason): void
    {
        DB::transaction(function () use ($user, $publication, $status, $reason): void {
            $p = NetworkPublication::whereKey($publication->id)->lockForUpdate()->firstOrFail();
            $allowed = ['draft' => ['submitted', 'archived'], 'returned' => ['submitted', 'archived'], 'submitted' => ['under_review', 'approved', 'returned', 'rejected', 'unpublished'], 'under_review' => ['approved', 'returned', 'rejected', 'unpublished'], 'approved' => ['published', 'unpublished'], 'published' => ['unpublished'], 'unpublished' => ['archived'], 'rejected' => ['archived']];
            abort_unless(in_array($status, $allowed[$p->status] ?? [], true), 422, 'This publication transition is not permitted.');
            if (in_array($status, ['under_review', 'approved', 'returned', 'rejected'], true)) {
                Gate::forUser($user)->authorize('review', $p);
            } elseif ($status === 'unpublished') {
                Gate::forUser($user)->authorize('unpublish', $p);
            } elseif ($status === 'published') {
                abort_unless(NetworkAccess::can($user, $p->institution, 'showcase.publish'), 403);
            } else {
                Gate::forUser($user)->authorize('update', $p);
            }
            if (in_array($status, ['submitted', 'approved', 'published'], true)) {
                abort_unless(NetworkAccess::publicScopeAllowed($p->institution, $p->visibility), 422, 'The institution has disabled this publication audience.');
                abort_if($p->consents()->where('version', $p->version)->whereNull('consented_at')->exists(), 422, 'Every named contributor must consent to this version before publication.');
                $valid = NetworkAccess::contributors($p->project);
                abort_if(array_diff($p->consents()->pluck('user_id')->all(), $valid), 422, 'Project contributors changed. Revise the attribution list.');
            }
            if ($status === 'approved') {
                $names = $p->consents()->with('user')->get()->map(fn ($c) => ['user_id' => $c->user_id, 'name' => $c->user->name])->all();
                $snapshot = $p->draft;
                $snapshot['team'] = $names;
                $snapshot['media_ids'] = $p->media()->orderBy('sort_order')->pluck('id')->all();
                $snapshot['project_status'] = $p->project_status;
                $snapshot['institution'] = $p->institution_attribution ? $p->institution->name : null;
                $snapshot['collaboration_open'] = $p->collaboration_open;
                $snapshot['visibility'] = $p->visibility;
                $p->approved_snapshot = $snapshot;
                $p->approved_by = $user->id;
                $p->approved_version = $p->version;
                $p->media()->update(['approved' => true]);
            }
            if ($status === 'published') {
                abort_unless($p->approved_by && $p->approved_version === $p->version && $p->approved_snapshot, 422, 'This exact version has not been independently approved.');
                $p->published_at = now();
            }
            $p->status = $status;
            $p->save();
            $p->decisions()->create(['actor_id' => $user->id, 'status' => $status, 'reason' => $reason, 'version' => $p->version, 'details' => ['approved_by' => $p->approved_by]]);
            NetworkSignals::record('publication.'.$status, $p, $p->institution_id, ['status' => $status, 'version' => $p->version]);
            $ids = [$p->submitted_by];
            if ($status === 'submitted') {
                $ids = NetworkSignals::reviewers($p->institution, 'showcase.review');
            }
            NetworkSignals::send($ids, 'network_publication', $p, $status.':'.$p->version, 'Showcase '.$status);
        });
    }

    public function image(User $user, NetworkPublication $publication, UploadedFile $file, string $alt): PublicationMedia
    {
        Gate::forUser($user)->authorize('update', $publication);
        abort_unless(in_array($publication->status, ['draft', 'returned', 'unpublished'], true), 422);
        abort_if($publication->media()->count() >= 8, 422, 'A publication supports at most eight approved images.');
        $info = getimagesize($file->getRealPath());
        abort_unless($info && $info[0] <= 4096 && $info[1] <= 4096 && $file->getSize() <= config('network.max_image_bytes'), 422);
        $source = imagecreatefromstring(file_get_contents($file->getRealPath()));
        abort_unless($source, 422);
        $ratio = min(1, 1600 / max($info[0], $info[1]));
        $image = imagecreatetruecolor((int) max(1, $info[0] * $ratio), (int) max(1, $info[1] * $ratio));
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagecopyresampled($image, $source, 0, 0, 0, 0, imagesx($image), imagesy($image), $info[0], $info[1]);
        ob_start();
        imagejpeg($image, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($image);
        imagedestroy($source);
        $path = 'network/publications/'.Str::ulid().'.jpg';
        abort_unless(Storage::disk('local')->put($path, $bytes), 422, 'Image storage is unavailable.');
        try {
            return DB::transaction(function () use ($publication, $user, $path, $alt, $bytes): PublicationMedia {
                $p = NetworkPublication::whereKey($publication->id)->lockForUpdate()->firstOrFail();
                abort_unless(in_array($p->status, ['draft', 'returned', 'unpublished'], true), 422);
                $media = $p->media()->create(['uploaded_by' => $user->id, 'path' => $path, 'alt' => $alt, 'mime' => 'image/jpeg', 'bytes' => strlen($bytes), 'sort_order' => $p->media()->count()]);
                $this->invalidate($p);
                NetworkSignals::record('publication.media_added', $p, $p->institution_id, ['version' => $p->version]);

                return $media;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function removeImage(User $user, PublicationMedia $media): void
    {
        $p = $media->publication;
        Gate::forUser($user)->authorize('update', $p);
        abort_unless(in_array($p->status, ['draft', 'returned', 'unpublished'], true), 422);
        DB::transaction(function () use ($p, $media): void {
            $media->delete();
            $this->invalidate($p);
        });
        Storage::disk('local')->delete($media->path);
    }

    private function invalidate(NetworkPublication $p): void
    {
        $p->update(['version' => $p->version + 1, 'status' => 'draft', 'approved_version' => null, 'approved_by' => null, 'approved_snapshot' => null]);
        $p->consents()->update(['version' => $p->version, 'consented_at' => null]);
        $p->media()->update(['approved' => false]);
    }

    public function presentation(NetworkPublication $p): array
    {
        $data = $p->approved_snapshot ?? [];
        $valid = NetworkAccess::contributors($p->project);
        $consents = $p->consents()->where('version', $p->approved_version)->whereNotNull('consented_at')->whereIn('user_id', $valid)->pluck('user_id')->all();
        $data['team'] = collect($data['team'] ?? [])->filter(fn ($u) => in_array($u['user_id'], $consents, true))->map(fn ($u) => ['name' => $u['name']])->values()->all();
        $data['media'] = $p->media()->where('approved', true)->whereIn('id', $data['media_ids'] ?? [])->orderBy('sort_order')->get();
        unset($data['acknowledgements'],$data['media_ids']);

        return $data;
    }
}

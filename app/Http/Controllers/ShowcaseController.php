<?php

namespace App\Http\Controllers;

use App\Http\Requests\PublicationRequest;
use App\Models\Institution;
use App\Models\NetworkPublication;
use App\Models\NetworkSetting;
use App\Models\Project;
use App\Models\PublicationMedia;
use App\Support\InnovationAccess;
use App\Support\NetworkAccess;
use App\Support\NetworkSignals;
use App\Support\PublicationWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ShowcaseController extends Controller
{
    public function index(Request $request): View
    {
        $d = $request->validate(['q' => 'nullable|string|max:180', 'category' => 'nullable|string|max:100', 'institution_id' => 'nullable|integer|min:1', 'technology' => 'nullable|string|max:50', 'status' => 'nullable|in:draft,pending_approval,approved,in_progress,on_hold,completed,cancelled,archived', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'collaboration' => 'nullable|boolean', 'sort' => 'nullable|in:newest,oldest,title']);
        $base = NetworkAccess::publications($request->user());
        $query = clone $base;
        if (! empty($d['q'])) {
            $query->where(fn ($q) => $q->where('title', 'like', '%'.$d['q'].'%')->orWhere('summary', 'like', '%'.$d['q'].'%'));
        }
        foreach (['category' => 'category', 'status' => 'project_status'] as $key => $column) {
            if (! empty($d[$key])) {
                $query->where($column, $d[$key]);
            }
        }
        if (! empty($d['institution_id'])) {
            $query->where('institution_attribution', true)->where('institution_id', $d['institution_id']);
        }
        if (! empty($d['technology'])) {
            $query->whereJsonContains('draft->technologies', $d['technology']);
        }
        if ($request->boolean('collaboration')) {
            $query->where('collaboration_open', true)->whereIn('visibility', ['network', 'public']);
        }
        if (! empty($d['from'])) {
            $query->where('published_at', '>=', CarbonImmutable::parse($d['from'], config('intelligence.timezone'))->utc());
        }
        if (! empty($d['to'])) {
            $query->where('published_at', '<', CarbonImmutable::parse($d['to'], config('intelligence.timezone'))->addDay()->utc());
        }
        match ($d['sort'] ?? 'newest') {
            'title' => $query->orderBy('title'),'oldest' => $query->orderBy('published_at'),default => $query->orderByDesc('published_at')
        };
        $publications = $query->with(['media' => fn ($q) => $q->where('approved', true)->orderBy('sort_order')->limit(1)])->paginate(12)->withQueryString();
        $categories = (clone $base)->distinct()->orderBy('category')->pluck('category');
        $institutions = Institution::whereIn('id', (clone $base)->where('institution_attribution', true)->select('institution_id'))->orderBy('name')->get(['id', 'name']);

        return view('network.showcase', compact('publications', 'categories', 'institutions'));
    }

    public function show(Request $request, NetworkPublication $publication): View
    {
        abort_unless(NetworkAccess::publicationVisible($request->user(), $publication), 404);
        $presentation = app(PublicationWorkflow::class)->presentation($publication);

        return view('network.showcase-detail', compact('publication', 'presentation'));
    }

    public function media(Request $request, NetworkPublication $publication, PublicationMedia $media): BinaryFileResponse
    {
        abort_unless($media->network_publication_id === $publication->id && $media->approved && in_array($media->id, $publication->approved_snapshot['media_ids'] ?? [], true) && NetworkAccess::publicationVisible($request->user(), $publication), 404);
        abort_unless(Storage::disk('local')->exists($media->path), 404);

        return response()->file(Storage::disk('local')->path($media->path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function mine(Request $request): View
    {
        $ids = InnovationAccess::memberships($request->user())->filter(fn ($m) => NetworkAccess::can($request->user(), $m->institution, 'showcase.submit'))->pluck('institution_id');
        $sources = InnovationAccess::projects($request->user())->where(fn ($q) => $q->where('owner_id', $request->user()->id)->orWhereHas('group.members', fn ($g) => $g->whereIn('membership_id', InnovationAccess::memberships($request->user())->pluck('id'))->where('role', 'leader')))->select('projects.id');
        $publications = NetworkPublication::whereIn('institution_id', $ids)->whereIn('project_id', $sources)->with('institution')->orderByDesc('updated_at')->paginate(15);

        return view('network.publications', compact('publications'));
    }

    public function form(Request $request, ?NetworkPublication $publication = null): View
    {
        if ($publication) {
            Gate::authorize('update', $publication);
            $project = $publication->project;
        } else {
            $project = $request->filled('project_id') ? Project::findOrFail($request->integer('project_id')) : null;
            if ($project) {
                abort_unless(NetworkAccess::representative($project, $request->user()), 403);
            }
        }
        $memberships = InnovationAccess::memberships($request->user())->filter(fn ($m) => NetworkAccess::can($request->user(), $m->institution, 'showcase.submit'));
        $projects = InnovationAccess::projects($request->user())->where(fn ($q) => $q->where('owner_id', $request->user()->id)->orWhereHas('group.members', fn ($g) => $g->whereIn('membership_id', $memberships->pluck('id'))->where('role', 'leader')))->orderBy('title')->limit(100)->get(['projects.id', 'projects.title']);
        $contributors = $project ? InnovationAccess::participants($project) : collect();
        $publication?->load('media', 'consents.user', 'decisions.actor');

        return view('network.publication-form', compact('publication', 'project', 'projects', 'memberships', 'contributors'));
    }

    public function save(PublicationRequest $request, ?NetworkPublication $publication = null): RedirectResponse
    {
        $p = app(PublicationWorkflow::class)->save($request->user(), $request->validated(), $publication);

        return redirect()->route('network.publications.edit', $p)->with('status', 'Showcase draft saved. Review consent and media before submitting for independent approval.');
    }

    public function reviewQueue(Request $request): View
    {
        $ids = InnovationAccess::memberships($request->user())->filter(fn ($m) => NetworkAccess::can($request->user(), $m->institution, 'showcase.review'))->pluck('institution_id');
        $publications = NetworkPublication::whereIn('institution_id', $ids)->whereIn('status', ['submitted', 'under_review', 'approved'])->where('submitted_by', '!=', $request->user()->id)->with('institution')->orderBy('created_at')->paginate(15);

        return view('network.publications', compact('publications') + ['reviewQueue' => true]);
    }

    public function review(Request $request, NetworkPublication $publication): View
    {
        Gate::authorize('review', $publication);
        $publication->load('media', 'consents.user', 'decisions.actor');

        return view('network.publication-review', compact('publication'));
    }

    public function action(Request $request, NetworkPublication $publication): RedirectResponse
    {
        $d = $request->validate(['status' => 'required|in:submitted,under_review,approved,returned,rejected,published,unpublished,archived', 'reason' => 'required|string|max:3000', 'confirm' => 'accepted']);
        app(PublicationWorkflow::class)->transition($request->user(), $publication, $d['status'], $d['reason']);

        return redirect()->route('network.publications.mine')->with('status', 'Publication decision recorded: '.str_replace('_', ' ', $d['status']).'.');
    }

    public function consentPage(Request $request, NetworkPublication $publication): View
    {
        abort_unless(in_array($request->user()->id, NetworkAccess::contributors($publication->project), true) && $publication->consents()->where('user_id', $request->user()->id)->exists(), 403);

        return view('network.publication-consent', compact('publication'));
    }

    public function consent(Request $request, NetworkPublication $publication): RedirectResponse
    {
        $d = $request->validate(['consent' => 'required|boolean', 'version' => 'required|integer', 'confirm' => 'accepted']);
        abort_unless($publication->version === (int) $d['version'], 409, 'The publication version changed. Read the new submission before deciding.');
        app(PublicationWorkflow::class)->consent($request->user(), $publication, (bool) $d['consent']);

        return redirect()->route('network.publications.consent', $publication)->with('status', 'Your attribution decision was recorded for this version.');
    }

    public function upload(Request $request, NetworkPublication $publication): RedirectResponse
    {
        $d = $request->validate(['file' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120|dimensions:max_width=4096,max_height=4096', 'alt' => 'required|string|max:180', 'confirm' => 'accepted']);
        app(PublicationWorkflow::class)->image($request->user(), $publication, $d['file'], $d['alt']);

        return redirect()->route('network.publications.edit', $publication)->with('status', 'Image optimised and stored privately. Media changes require fresh version consent.');
    }

    public function privateMedia(Request $request, PublicationMedia $media): BinaryFileResponse
    {
        $p = $media->publication;
        $u = $request->user();
        abort_unless(NetworkAccess::publicationEdit($u, $p) || NetworkAccess::publicationReview($u, $p) || ($p->consents()->where('user_id', $u->id)->exists() && in_array($u->id, NetworkAccess::contributors($p->project), true)), 403);
        abort_unless(Storage::disk('local')->exists($media->path), 404);

        return response()->file(Storage::disk('local')->path($media->path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function removeMedia(Request $request, PublicationMedia $media): RedirectResponse
    {
        $request->validate(['confirm' => 'accepted']);
        $p = $media->publication;
        app(PublicationWorkflow::class)->removeImage($request->user(), $media);

        return redirect()->route('network.publications.edit', $p)->with('status', 'Image removed. Review version consent again.');
    }

    public function policy(Request $request, Institution $institution): View|RedirectResponse
    {
        abort_unless(NetworkAccess::can($request->user(), $institution, 'network.policy'), 403);
        $settings = NetworkAccess::setting($institution->id);
        if ($request->isMethod('post')) {
            $d = $request->validate(['allow_network' => 'required|boolean', 'allow_public' => 'required|boolean', 'require_collaboration_approval' => 'required|boolean', 'reason' => 'required|string|max:2000', 'confirm' => 'accepted']);
            $previous = $settings->only(['allow_network', 'allow_public', 'require_collaboration_approval']);
            $settings = NetworkSetting::updateOrCreate(['institution_id' => $institution->id],collect($d)->only(['allow_network', 'allow_public', 'require_collaboration_approval'])->all());
            NetworkSignals::record('institution.policy_updated',$settings,$institution->id,['previous' => $previous, 'current' => $settings->only(array_keys($previous)), 'reason' => $d['reason']]);

            return redirect()->route('network.policy',$institution)->with('status','Institution discovery policy updated. Disabled audiences lose access immediately.');
        }

        return view('network.policy',compact('institution','settings'));
    }
}

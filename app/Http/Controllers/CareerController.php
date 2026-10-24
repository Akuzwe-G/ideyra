<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfessionalEntryRequest;
use App\Http\Requests\ProfessionalProfileRequest;
use App\Models\Institution;
use App\Models\PortfolioMedia;
use App\Models\ProfessionalEntry;
use App\Models\Project;
use App\Models\ProjectShowcase;
use App\Support\Audit;
use App\Support\CareerAccess;
use App\Support\CareerAnalytics;
use App\Support\CareerWorkflow;
use App\Support\DashboardAnalytics;
use App\Support\InnovationAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CareerController extends Controller
{
    public function index(Request $request, CareerWorkflow $workflow): View
    {
        $request->validate(['tab' => 'nullable|in:overview,profile,education,skill,experience,certification,achievement,language,project', 'q' => 'nullable|string|max:180', 'edit' => 'nullable|integer', 'institution_id' => 'nullable|integer']);
        $profile = $workflow->profile();
        $tab = $request->input('tab', 'overview');
        $query = $profile->entries()->when(array_key_exists($tab, config('career.entry_types')), fn ($q) => $q->where('type', $tab))
            ->when($request->filled('q'), fn ($q) => $q->where('title', 'like', '%'.$request->string('q').'%'));
        $entries = $query->paginate(12)->withQueryString();
        $entry = $request->filled('edit') ? $profile->entries()->findOrFail($request->integer('edit')) : new ProfessionalEntry(['type' => $tab, 'content' => []]);
        $showcases = $profile->showcases()->with('project.institution', 'media')->get()->filter(fn ($s) => CareerAccess::projectContent($s) !== null)
            ->filter(fn ($s) => ! $request->filled('q') || str_contains(mb_strtolower($s->project->title), mb_strtolower($request->string('q'))));
        $projects = InnovationAccess::projects($request->user())->get()->filter(fn ($p) => CareerAccess::role($p, $request->user()) !== null);
        $memberships = InnovationAccess::memberships($request->user());
        $completeness = $workflow->completeness($profile);
        $filters = app(DashboardAnalytics::class)->filters($request);
        $institution = $request->filled('institution_id') ? Institution::findOrFail($request->integer('institution_id')) : null;
        $analytics = app(CareerAnalytics::class)->data($profile, $filters, $institution);

        return view('career.index', compact('profile', 'tab', 'entries', 'entry', 'showcases', 'projects', 'memberships', 'completeness', 'filters', 'analytics', 'institution'));
    }

    public function profile(ProfessionalProfileRequest $request, CareerWorkflow $workflow): RedirectResponse
    {
        $workflow->saveProfile($request->validated());

        return redirect()->route('career.index', ['tab' => 'profile'])->with('status', 'Professional profile saved. Public content changes only when you explicitly publish an update.');
    }

    public function entry(ProfessionalEntryRequest $request, CareerWorkflow $workflow, ?ProfessionalEntry $entry = null): RedirectResponse
    {
        $workflow->saveEntry($request->validated(), $entry);

        return redirect()->route('career.index', ['tab' => $request->input('type')])->with('status', 'Profile entry saved.');
    }

    public function deleteEntry(ProfessionalEntry $entry): RedirectResponse
    {
        Gate::authorize('update', $entry->profile);
        abort_if($entry->verifications()->exists(), 422, 'A verification history exists. Remove this entry from your portfolio selection instead.');
        Audit::record('career.entry_deleted', $entry);
        $path = $entry->evidence_path;
        $entry->delete();
        if ($path) {
            Storage::disk('local')->delete($path);
        }

        return redirect()->route('career.index', ['tab' => $entry->type])->with('status', 'Entry removed.');
    }

    public function evidence(ProfessionalEntry $entry): StreamedResponse
    {
        $own = CareerAccess::own(auth()->user(), $entry->profile->user_id);
        $review = $entry->verifications()->whereIn('status', ['requested', 'under_review'])->get()->contains(fn ($v) => CareerAccess::canReview($v, auth()->user()));
        abort_unless($own || $review, 403);
        abort_unless($entry->evidence_path && Storage::disk('local')->exists($entry->evidence_path), 404);

        return Storage::disk('local')->download($entry->evidence_path, $entry->evidence_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function showcase(Request $request, CareerWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['project_id' => 'required|integer|exists:projects,id', 'reflection' => 'nullable|string|max:5000', 'technologies' => 'nullable|string|max:500', 'links' => 'nullable|array:demo,github,website', 'links.*' => 'nullable|url:http,https|max:500', 'sort_order' => 'nullable|integer|min:0|max:10000']);
        $project = Project::findOrFail($data['project_id']);
        abort_unless(CareerAccess::role($project, $request->user()) !== null, 403);
        $showcase = $workflow->profile()->showcases()->updateOrCreate(['project_id' => $project->id], collect($data)->except('project_id')->all());
        Audit::record('career.showcase_saved', $showcase, $project->institution_id);

        return redirect()->route('career.index', ['tab' => 'project'])->with('status', 'Showcase saved with your actual project role. Institutional publication requires specific approval.');
    }

    public function removeShowcase(ProjectShowcase $showcase): RedirectResponse
    {
        Gate::authorize('update', $showcase->profile);
        abort_if($showcase->verifications()->exists(), 422, 'This showcase has verification history. Deselect it in the portfolio builder instead.');
        $paths = $showcase->media()->pluck('path')->all();
        Audit::record('career.showcase_removed', $showcase);
        $showcase->delete();
        Storage::disk('local')->delete($paths);

        return redirect()->route('career.index', ['tab' => 'project'])->with('status', 'Showcase removed.');
    }

    public function media(Request $request, ProjectShowcase $showcase): RedirectResponse
    {
        Gate::authorize('update', $showcase->profile);
        abort_unless(CareerAccess::projectContent($showcase), 403);
        $data = $request->validate(['image' => 'required|image|mimes:png,jpg,jpeg,webp|max:4096|dimensions:max_width=4000,max_height=4000', 'alt' => 'required|string|max:250', 'sort_order' => 'nullable|integer|min:0|max:10000']);
        $path = app(CareerWorkflow::class)->storeImage($data['image'], 'portfolio-media');
        $showcase->media()->create(['path' => $path, 'mime' => Storage::disk('local')->mimeType($path), 'alt' => $data['alt'], 'sort_order' => $data['sort_order'] ?? 0]);

        return redirect()->route('career.index', ['tab' => 'project'])->with('status', 'Image stored privately. Explicit public approval is required.');
    }

    public function mediaAction(Request $request, PortfolioMedia $media): RedirectResponse
    {
        $action = $request->validate(['action' => 'required|in:approve,revoke,remove', 'sort_order' => 'nullable|integer|min:0|max:10000']);
        $showcase = $media->showcase;
        $owner = CareerAccess::own($request->user(), $showcase->profile->user_id);
        $staff = $showcase->project->institution && CareerAccess::reviewer($request->user(), $showcase->project->institution, $showcase->project);
        abort_unless(($owner && (! $showcase->project->institution_id || in_array($action['action'], ['remove', 'revoke'], true))) || $staff, 403);
        if ($action['action'] === 'remove') {
            $path = $media->path;
            $media->delete();
            Storage::disk('local')->delete($path);
        } else {
            abort_if($action['action'] === 'approve' && ! CareerAccess::publicProject($showcase), 422, 'Approve the exact project showcase before approving media.');
            $media->update(['public_approved' => $action['action'] === 'approve', 'approved_by' => $request->user()->id, 'sort_order' => $action['sort_order'] ?? $media->sort_order]);
        }
        Audit::record('career.media_'.$action['action'], $showcase, $showcase->project->institution_id);

        return redirect()->route($owner ? 'career.index' : 'career.verifications', $owner ? ['tab' => 'project'] : [])->with('status', 'Media decision saved.');
    }

    public function privateMedia(PortfolioMedia $media): StreamedResponse
    {
        $showcase = $media->showcase;
        abort_if(CareerAccess::own(auth()->user(), $showcase->profile->user_id) && CareerAccess::projectContent($showcase) === null, 403);
        abort_unless(CareerAccess::own(auth()->user(), $showcase->profile->user_id) || ($showcase->project->institution && CareerAccess::reviewer(auth()->user(), $showcase->project->institution, $showcase->project)), 403);

        return Storage::disk('local')->response($media->path, null, ['Content-Type' => $media->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}

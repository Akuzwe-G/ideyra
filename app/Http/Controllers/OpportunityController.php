<?php

namespace App\Http\Controllers;

use App\Http\Requests\OpportunityRequest;
use App\Models\Opportunity;
use App\Models\OpportunityCategory;
use App\Support\InnovationAccess;
use App\Support\InstitutionAccess;
use App\Support\NetworkAccess;
use App\Support\OpportunityAccess;
use App\Support\OpportunityWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OpportunityController extends Controller
{
    public function index(Request $request): View
    {
        $request->validate(['q' => 'nullable|string|max:180', 'category_id' => 'nullable|integer', 'country' => 'nullable|string|max:100', 'delivery_mode' => 'nullable|in:online,in_person,hybrid', 'deadline_before' => 'nullable|date_format:Y-m-d', 'institution_id' => 'nullable|integer', 'eligible' => 'nullable|boolean', 'state' => 'nullable|in:open,closed', 'sort' => 'nullable|in:newest,deadline,title', 'manage' => 'nullable|boolean']);
        $manage = $request->boolean('manage');
        $saved = $request->routeIs('opportunities.saved');
        $query = OpportunityAccess::visible($request->user(), $manage)->with('category', 'institution');
        if ($manage) {
            $query->whereIn('id', OpportunityAccess::visible($request->user())->get()->filter(fn ($o) => Gate::allows('update', $o) || OpportunityAccess::manages($o, $request->user(), 'opportunities.approve'))->pluck('id'));
        }
        if ($saved) {
            $query->whereIn('id', DB::table('opportunity_bookmarks')->where('user_id', $request->user()->id)->select('opportunity_id'));
        }
        $query->when($request->filled('q'), fn ($q) => $q->where(fn ($s) => $s->where('title', 'like', '%'.$request->string('q').'%')->orWhere('summary', 'like', '%'.$request->string('q').'%')->orWhere('organiser', 'like', '%'.$request->string('q').'%')));
        foreach (['category_id', 'country', 'delivery_mode', 'institution_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
        $query->when($request->filled('deadline_before'), fn ($q) => $q->whereDate('deadline_at', '<=', $request->string('deadline_before')));
        if ($request->query('state') === 'open') {
            $query->where('status', 'published')->where(fn ($q) => $q->whereNull('opens_at')->orWhere('opens_at', '<=', now()))->where(fn ($q) => $q->whereNull('deadline_at')->orWhere('deadline_at', '>=', now()));
        }
        if ($request->query('state') === 'closed') {
            $query->where(fn ($q) => $q->where('status', 'closed')->orWhere(fn ($expired) => $expired->where('status', 'published')->where('deadline_at', '<', now())));
        }
        $memberships = InnovationAccess::memberships($request->user());
        if ($request->boolean('eligible')) {
            $ids = (clone $query)->get()->filter(fn ($o) => $memberships->contains(fn ($m) => OpportunityAccess::eligible($o, $request->user(), $m->institution_id)))->pluck('id');
            $query->whereIn('id', $ids);
        }
        match ($request->query('sort', 'newest')) {
            'deadline' => $query->orderByRaw('deadline_at IS NULL')->orderBy('deadline_at'),'title' => $query->orderBy('title'),default => $query->latest('created_at')
        };
        $opportunities = $query->paginate(12)->withQueryString();
        $categories = OpportunityCategory::where('active', true)->orderBy('name')->get();
        $bookmarks = DB::table('opportunity_bookmarks')->where('user_id', $request->user()->id)->pluck('opportunity_id')->all();

        return view('opportunities.index', compact('opportunities', 'categories', 'bookmarks', 'memberships', 'manage', 'saved'));
    }

    public function create(Request $request): View
    {
        return $this->form($request, new Opportunity(['application_method' => 'internal', 'visibility' => 'platform', 'delivery_mode' => 'online', 'status' => 'draft']));
    }

    public function edit(Request $request, Opportunity $opportunity): View
    {
        Gate::authorize('update', $opportunity);

        return $this->form($request, $opportunity);
    }

    private function form(Request $request, Opportunity $opportunity): View
    {
        $memberships = InnovationAccess::memberships($request->user())->filter(fn ($m) => InnovationAccess::can($m->institution, 'opportunities.create', $request->user()));
        $institutionId = $opportunity->exists ? $opportunity->institution_id : ($request->filled('institution_id') ? $request->integer('institution_id') : (InstitutionAccess::system() ? null : $memberships->first()?->institution_id));
        abort_unless($opportunity->exists || ($institutionId ? $memberships->contains('institution_id', $institutionId) : InstitutionAccess::system()), 403);
        $opportunity->institution_id = $institutionId;
        if (! $opportunity->exists) {
            $opportunity->visibility = $institutionId ? 'institution' : 'platform';
        }
        $categories = OpportunityCategory::where('active', true)->orderBy('name')->get();
        $targets = [];
        foreach (OpportunityWorkflow::TARGETS as $kind => $table) {
            $targets[$kind] = DB::table($table)->when($kind === 'role', fn ($q) => $q->where('scope', 'institution'), fn ($q) => $q->where('institution_id', $institutionId ?? 0))->orderBy('name')->get(['id', 'name']);
        }
        $questions = $opportunity->exists ? DB::table('opportunity_questions')->where('opportunity_id', $opportunity->id)->orderBy('sort_order')->get()->map(fn ($q) => ['label' => $q->label, 'type' => $q->type, 'required' => $q->required, 'options_text' => implode(', ', json_decode($q->options, true) ?? [])])->all() : [];
        $audience = $opportunity->exists ? DB::table('opportunity_audiences')->where('opportunity_id', $opportunity->id)->get()->groupBy('kind')->map(fn ($items) => $items->pluck('reference_id')->all())->all() : [];

        return view('opportunities.form', compact('opportunity', 'memberships', 'institutionId', 'categories', 'targets', 'questions', 'audience'));
    }

    public function store(OpportunityRequest $request, OpportunityWorkflow $workflow): RedirectResponse
    {
        return redirect()->route('opportunities.show', $workflow->save($request->validated()))->with('status', 'Opportunity draft saved. Review and publication require separate permissions.');
    }

    public function update(OpportunityRequest $request, Opportunity $opportunity, OpportunityWorkflow $workflow): RedirectResponse
    {
        $workflow->save($request->validated(), $opportunity);

        return redirect()->route('opportunities.show', $opportunity)->with('status', 'Opportunity updated.');
    }

    public function show(Opportunity $opportunity): View|RedirectResponse
    {
        Gate::authorize('view', $opportunity);
        if ($opportunity->network_type === 'challenge') {
            return redirect()->route('network.challenges.show', NetworkAccess::challenges(auth()->user())->where('opportunity_id', $opportunity->id)->firstOrFail());
        }
        if ($opportunity->network_type === 'event') {
            return redirect()->route('network.events.show', NetworkAccess::events(auth()->user())->where('opportunity_id', $opportunity->id)->firstOrFail());
        }
        $opportunity->load('category', 'institution', 'creator', 'managers');
        $questions = DB::table('opportunity_questions')->where('opportunity_id', $opportunity->id)->orderBy('sort_order')->get();
        $bookmarked = DB::table('opportunity_bookmarks')->where('opportunity_id', $opportunity->id)->where('user_id', auth()->id())->exists();
        $memberships = InnovationAccess::memberships(auth()->user());
        foreach ($memberships as $member) {
            if ($member->role->name === 'Student' && (! $opportunity->institution_id || $opportunity->institution_id === $member->institution_id) && OpportunityAccess::eligible($opportunity, auth()->user(), $member->institution_id)) {
                DB::table('institution_opportunity_visits')->insertOrIgnore([
                    'institution_id' => $member->institution_id, 'user_id' => auth()->id(), 'opportunity_id' => $opportunity->id,
                    'visited_on' => today(config('intelligence.timezone'))->toDateString(), 'created_at' => now(),
                ]);
            }
        }
        $eligibleMemberships = $memberships->filter(fn ($m) => OpportunityAccess::eligible($opportunity, auth()->user(), $m->institution_id));
        $decisions = Gate::allows('update', $opportunity) || OpportunityAccess::manages($opportunity, auth()->user(), 'opportunities.approve') ? DB::table('opportunity_decisions')->where('opportunity_id', $opportunity->id)->latest('id')->get() : collect();
        $managers = $opportunity->institution_id && OpportunityAccess::manages($opportunity, auth()->user()) ? $opportunity->institution->memberships()->with('user')->where('status', 'active')->get()->filter(fn ($m) => OpportunityAccess::permission($opportunity, 'opportunities.manage', $m->user)) : collect();

        return view('opportunities.show', compact('opportunity', 'questions', 'bookmarked', 'memberships', 'eligibleMemberships', 'decisions', 'managers'));
    }

    public function action(Request $request, Opportunity $opportunity, OpportunityWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['action' => 'required|in:transition,bookmark,manager,unassign', 'status' => 'nullable|in:draft,submitted,approved,returned,rejected,published,closed,archived', 'reason' => 'nullable|string|max:6000', 'membership_id' => 'nullable|integer', 'user_id' => 'nullable|integer|exists:users,id', 'saved' => 'nullable|boolean']);
        if ($data['action'] === 'unassign') {
            $request->validate(['user_id' => 'required']);
            $workflow->removeManager($opportunity, $data['user_id']);
        } elseif ($data['action'] === 'transition') {
            $request->validate(['status' => 'required', 'reason' => 'required']);
            $workflow->transition($opportunity, $data['status'], $data['reason']);
        } elseif ($data['action'] === 'manager') {
            $request->validate(['membership_id' => 'required']);
            $workflow->manager($opportunity, $data['membership_id']);
        } else {
            Gate::authorize('view', $opportunity);
            if ($request->boolean('saved')) {
                DB::table('opportunity_bookmarks')->insertOrIgnore(['opportunity_id' => $opportunity->id, 'user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('opportunity_bookmarks')->where('opportunity_id', $opportunity->id)->where('user_id', $request->user()->id)->delete();
            }
        }

        return back()->with('status', 'Opportunity action saved.');
    }

    public function cover(Opportunity $opportunity): StreamedResponse
    {
        Gate::authorize('view', $opportunity);
        abort_unless($opportunity->cover_path && Storage::disk('local')->exists($opportunity->cover_path), 404);

        return Storage::disk('local')->response($opportunity->cover_path, null, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function categories(Request $request): View|RedirectResponse
    {
        InstitutionAccess::requireSystem();
        if ($request->isMethod('post')) {
            $data = $request->validate(['id' => 'nullable|integer|exists:opportunity_categories,id', 'name' => ['required', 'string', 'max:100', Rule::unique('opportunity_categories', 'name')->ignore($request->integer('id'))], 'active' => 'required|boolean']);
            $category = empty($data['id']) ? new OpportunityCategory(['slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(6))]) : OpportunityCategory::findOrFail($data['id']);
            $category->fill(collect($data)->only('name', 'active')->all());
            $category->save();

            return back()->with('status', 'Category saved.');
        }
        $categories = OpportunityCategory::withCount('opportunities')->orderBy('name')->paginate(20);

        return view('opportunities.categories', compact('categories'));
    }
}

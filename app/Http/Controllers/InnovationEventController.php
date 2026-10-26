<?php

namespace App\Http\Controllers;

use App\Http\Requests\EventRequest;
use App\Models\EventRegistration;
use App\Models\ExhibitionProject;
use App\Models\InnovationChallenge;
use App\Models\InnovationEvent;
use App\Models\Institution;
use App\Support\EventWorkflow;
use App\Support\InnovationAccess;
use App\Support\NetworkAccess;
use App\Support\NetworkSignals;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class InnovationEventController extends Controller
{
    public function index(Request $request): View
    {
        $d = $request->validate(['q' => 'nullable|string|max:180', 'kind' => 'nullable|string|max:40', 'institution_id' => 'nullable|integer', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $q = NetworkAccess::events($request->user());
        $institutions = Institution::whereIn('id', NetworkAccess::events($request->user())->select('institution_id'))->orderBy('name')->get(['id', 'name']);
        if (! empty($d['q'])) {
            $q->where('title', 'like', '%'.$d['q'].'%');
        }foreach (['kind', 'institution_id'] as $key) {
            if (! empty($d[$key])) {
                $q->where($key, $d[$key]);
            }
        }if (! empty($d['from'])) {
            $q->whereDate('starts_at', '>=', $d['from']);
        }if (! empty($d['to'])) {
            $q->whereDate('starts_at', '<=', $d['to']);
        }
        $events = $q->with('institution')->orderBy('starts_at')->paginate(12)->withQueryString();
        $memberships = $request->user() ? InnovationAccess::memberships($request->user())->filter(fn ($m) => NetworkAccess::can($request->user(), $m->institution, 'events.create')) : collect();

        return view('network.events', compact('events', 'memberships', 'institutions'));
    }

    public function form(Request $request, ?InnovationEvent $event = null): View
    {
        if ($event) {
            Gate::authorize('update', $event);
        }
        $memberships = InnovationAccess::memberships($request->user())->filter(fn ($m) => NetworkAccess::can($request->user(), $m->institution, $event ? 'events.manage' : 'events.create'));
        $challenges = InnovationChallenge::whereIn('institution_id', $memberships->pluck('institution_id'))->whereDoesntHave('event')->with('opportunity')->limit(100)->get();

        return view('network.event-form', compact('event', 'memberships', 'challenges'));
    }

    public function save(EventRequest $request, ?InnovationEvent $event = null): RedirectResponse
    {
        $event = app(EventWorkflow::class)->save($request->user(), $request->validated(), $event);

        return redirect()->route('network.events.show', $event)->with('status', 'Event draft saved. Independent approval is required before publication.');
    }

    public function show(Request $request, InnovationEvent $event): View
    {
        abort_unless(NetworkAccess::events($request->user())->whereKey($event->id)->exists(), 404);
        $event->load('institution', 'challenge.opportunity');
        $manager = $request->user() && NetworkAccess::eventManage($request->user(), $event);
        $exhibits = $event->exhibits()->whereIn('network_publication_id', NetworkAccess::publications($request->user())->select('network_publications.id'))->with('publication')->orderBy('sort_order')->paginate(12, ['*'], 'gallery_page');
        $registration = $request->user() ? $event->registrations()->where('user_id', $request->user()->id)->first() : null;
        $registrations = $manager ? $event->registrations()->with('user', 'institution')->orderByDesc('id')->paginate(20) : null;
        $memberships = $request->user() ? InnovationAccess::memberships($request->user()) : collect();
        $publications = $manager ? NetworkAccess::publications($request->user())->orderBy('title')->limit(100)->get() : collect();

        return view('network.event-detail', compact('event', 'manager', 'exhibits', 'registration', 'registrations', 'memberships', 'publications'));
    }

    public function action(Request $request, InnovationEvent $event): RedirectResponse
    {
        $d = $request->validate(['status' => 'required|in:submitted,approved,returned,rejected,published,unpublished,completed,archived,draft', 'reason' => 'required|string|max:3000', 'confirm' => 'accepted']);
        app(EventWorkflow::class)->transition($request->user(), $event, $d['status'], $d['reason']);

        return redirect()->route('network.events.show', $event)->with('status', 'Event decision recorded.');
    }

    public function register(Request $request, InnovationEvent $event): RedirectResponse
    {
        $d = $request->validate(['institution_id' => 'required|integer', 'confirm' => 'accepted']);
        app(EventWorkflow::class)->register($request->user(), $event, (int) $d['institution_id']);

        return redirect()->route('network.events.show', $event)->with('status', 'Event registration confirmed.');
    }

    public function cancel(Request $request, EventRegistration $registration): RedirectResponse
    {
        $request->validate(['confirm' => 'accepted']);
        app(EventWorkflow::class)->cancel($request->user(), $registration);

        return redirect()->route('network.events.show', $registration->event)->with('status', 'Registration cancelled.');
    }

    public function attendance(Request $request, EventRegistration $registration): RedirectResponse
    {
        $d = $request->validate(['attended' => 'required|boolean', 'confirm' => 'accepted']);
        app(EventWorkflow::class)->attendance($request->user(), $registration, (bool) $d['attended']);

        return redirect()->route('network.events.show', $registration->event)->with('status', 'Attendance recorded. Achievement verification remains separate.');
    }

    public function feature(Request $request, InnovationEvent $event): RedirectResponse
    {
        $d = $request->validate(['network_publication_id' => 'required|integer', 'category' => 'required|string|max:100', 'sort_order' => 'nullable|integer|min:0|max:10000', 'presentation_at' => 'nullable|date', 'demo_url' => 'nullable|url:http,https|max:2048', 'confirm' => 'accepted']);
        Gate::authorize('update', $event);
        $publication = NetworkAccess::publications($request->user())->whereKey($d['network_publication_id'])->firstOrFail();
        $d['representative_id'] = $publication->submitted_by;
        app(EventWorkflow::class)->feature($request->user(), $event, $d);

        return redirect()->route('network.events.show', $event)->with('status', 'Approved projection added to the exhibition.');
    }

    public function removeFeature(Request $request, ExhibitionProject $exhibit): RedirectResponse
    {
        $request->validate(['confirm' => 'accepted']);
        Gate::authorize('update', $exhibit->event);
        $event = $exhibit->event;
        $exhibit->delete();
        NetworkSignals::record('event.feature_removed', $event, $event->institution_id);

        return redirect()->route('network.events.show', $event)->with('status', 'Exhibition assignment removed.');
    }

    public function reschedule(Request $request, InnovationEvent $event): RedirectResponse
    {
        $d = $request->validate(['starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at', 'reason' => 'required|string|max:3000', 'confirm' => 'accepted']);
        app(EventWorkflow::class)->reschedule($request->user(), $event, $d);

        return redirect()->route('network.events.show', $event)->with('status', 'Schedule updated. Registered participants notified in-app; review agenda slots.');
    }
}

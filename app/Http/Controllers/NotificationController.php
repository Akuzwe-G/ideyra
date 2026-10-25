<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use App\Models\JoinApplication;
use App\Models\ReportRun;
use App\Support\InnovationAccess;
use App\Support\InstitutionAccess;
use App\Support\OpportunityAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $request->validate(['filter' => ['nullable', 'in:unread,read']]);
        $notifications = $request->user()->notifications()->when($request->query('filter') === 'unread', fn ($query) => $query->whereNull('read_at'))->when($request->query('filter') === 'read', fn ($query) => $query->whereNotNull('read_at'))->paginate(15)->withQueryString();

        return view('identity.notifications', compact('notifications'));
    }

    public function update(Request $request, string $notification): RedirectResponse
    {
        $item = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $data = $request->validate(['read' => ['required', 'boolean']]);
        $data['read'] ? $item->markAsRead() : $item->markAsUnread();

        return back()->with('status', 'Notification updated.');
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('status', 'All notifications marked as read.');
    }

    public function open(Request $request, string $notification): RedirectResponse
    {
        $data = $request->user()->notifications()->whereKey($notification)->firstOrFail()->data;
        if (($data['target'] ?? null) === 'report') {
            $run = ReportRun::find($data['subject_id'] ?? 0);
            if ($run && Gate::allows('view', $run)) {
                return redirect()->route('reports.show', $run);
            }

            return redirect()->route('notifications.index')->with('status', 'This report is no longer available within your current access.');
        }
        if (($data['target'] ?? null) === 'career_portfolio') {
            return redirect()->route('career.portfolio');
        }
        if (($data['target'] ?? null) === 'career') {
            return redirect()->route('career.verifications');
        }
        if (in_array($data['target'] ?? null, ['opportunity', 'opportunity_application', 'sponsorship'], true)) {
            [$query,$route] = match ($data['target']) {
                'opportunity' => [OpportunityAccess::visible($request->user()), 'opportunities.show'],
                'opportunity_application' => [OpportunityAccess::applications($request->user()), 'opportunity-applications.show'],
                'sponsorship' => [OpportunityAccess::sponsorships($request->user()), 'sponsorships.show'],
            };
            if ($record = $query->whereKey($data['subject_id'] ?? 0)->first()) {
                return redirect()->route($route, $record);
            }

            return redirect()->route('notifications.index')->with('status', 'This record is no longer available in your authorised context.');
        }
        foreach (['idea' => [InnovationAccess::ideas($request->user()), 'ideas.show'], 'group' => [InnovationAccess::groups($request->user()), 'groups.show'], 'project' => [InnovationAccess::projects($request->user()), 'projects.show']] as $target => [$query,$route]) {
            if (($data['target'] ?? null) === $target && $record = $query->whereKey($data['subject_id'] ?? 0)->first()) {
                return redirect()->route($route, $record);
            }
        }
        if (($data['target'] ?? null) === 'application' && InstitutionAccess::system()) {
            $application = JoinApplication::find($data['subject_id']);
            if ($application) {
                return redirect()->route('system.applications.show', $application);
            }
        }
        if (in_array($data['target'] ?? null, ['institution', 'membership'], true) && $institution = Institution::find($data['institution_id'])) {
            if (InstitutionAccess::system()) {
                return redirect()->route('institutions.edit', $institution);
            }
            if (InstitutionAccess::membership($institution)) {
                return redirect()->route('institution.workspace', $institution);
            }
        }
        if (($data['target'] ?? null) === 'profile') {
            return redirect()->route('profile');
        }

        return redirect()->route('notifications.index')->with('status', 'This record is no longer available in your authorised context.');
    }
}

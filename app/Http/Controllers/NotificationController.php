<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use App\Models\JoinApplication;
use App\Support\InstitutionAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

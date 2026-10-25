<?php

namespace App\Http\Controllers;

use App\Models\AcademicClass;
use App\Models\IdentityInvitation;
use App\Models\Institution;
use App\Models\User;
use App\Support\Audit;
use App\Support\DashboardAnalytics;
use App\Support\IdentityOnboarding;
use App\Support\InnovationAccess;
use App\Support\InstitutionAccess;
use App\Support\OpportunityAnalytics;
use App\Support\ProjectAnalytics;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class IdentityController extends Controller
{
    public function dashboard(Request $request, DashboardAnalytics $analytics): View|RedirectResponse
    {
        if (InstitutionAccess::system($request->user())) {
            return redirect()->route('system.dashboard');
        }
        $memberships = auth()->user()->memberships()->with('institution', 'role')->where('status', 'active')->whereHas('institution', fn ($query) => $query->where('status', 'active'))->get();

        $filters = $analytics->filters($request);
        $projectAnalytics = app(ProjectAnalytics::class)->data($filters);
        $innovation = ['My ideas' => InnovationAccess::ideas(auth()->user())->where('owner_id', auth()->id())->count(), 'My projects' => InnovationAccess::projects(auth()->user())->count(), 'My groups' => InnovationAccess::groups(auth()->user())->count()];
        $recentProjects = InnovationAccess::projects(auth()->user())->withCount(['tasks', 'tasks as done_count' => fn ($q) => $q->where('status', 'completed'), 'deliverables', 'deliverables as approved_count' => fn ($q) => $q->where('status', 'approved')])->latest()->limit(4)->get();

        $opportunitySummary = app(OpportunityAnalytics::class)->personalSummary(auth()->user());

        return view('identity.dashboard', compact('memberships', 'filters', 'projectAnalytics', 'innovation', 'recentProjects', 'opportunitySummary'));
    }

    public function workspace(Request $request, Institution $institution, DashboardAnalytics $analytics): View
    {
        InstitutionAccess::authorize($institution);
        $member = InstitutionAccess::membership($institution);
        $classes = AcademicClass::with('programme', 'intake', 'academicYear')->where('institution_id', $institution->id)
            ->when(! InstitutionAccess::allows($institution, 'academic.classes'), fn ($query) => $query->whereIn('id', DB::table('class_assignments')->where('membership_id', $member?->id)->select('academic_class_id')))
            ->orderBy('name')->paginate(15);

        $filters = $analytics->filters($request);
        $data = DashboardAnalytics::canViewInstitution($institution) ? $analytics->institution($institution, $filters) : null;
        $overview = [];
        if ($data) {
            if (InstitutionAccess::allows($institution, 'members.view')) {
                $activeMembers = $institution->memberships()->where('status', 'active')->whereHas('user', fn ($query) => $query->where('status', 'active'));
                $overview['Students'] = (clone $activeMembers)->whereHas('role', fn ($query) => $query->where('name', 'Student'))->count();
                $overview['Lecturers and mentors'] = (clone $activeMembers)->whereHas('role', fn ($query) => $query->whereIn('name', ['Lecturer / Teacher', 'Mentor / Supervisor']))->count();
            }
            foreach (['years' => ['academic_years', 'Academic years'], 'intakes' => ['intakes', 'Intakes'], 'programmes' => ['programmes', 'Programmes'], 'classes' => ['academic_classes', 'Classes']] as $permission => [$table, $label]) {
                if (InstitutionAccess::allows($institution, 'academic.'.$permission)) {
                    $overview[$label] = DB::table($table)->where('institution_id', $institution->id)->count();
                }
            }
        }

        return view('institution.dashboard', compact('institution', 'member', 'classes', 'filters', 'overview') + ['analytics' => $data]);
    }

    public function profile(Request $request): View|RedirectResponse
    {
        if ($request->isMethod('post')) {
            $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'phone' => ['nullable', 'string', 'max:40'],
                'password' => ['nullable', 'confirmed', 'max:255', PasswordRule::min(12)->mixedCase()->numbers()],
                'avatar' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096'],
                'remove_avatar' => ['nullable', 'boolean'],
                'current_password' => ['required_with:password', 'nullable', 'current_password']]);
            $newAvatar = $request->file('avatar')?->store('avatars', 'local');
            if ($request->hasFile('avatar') && ! $newAvatar) {
                throw ValidationException::withMessages(['avatar' => 'The picture could not be stored. Please try again.']);
            }
            $oldAvatar = $request->user()->avatar_path;
            try {
                DB::transaction(function () use ($data, $newAvatar): void {
                    $user = auth()->user();
                    $user->forceFill(collect($data)->only(['name', 'phone'])->all());
                    if ($newAvatar || ($data['remove_avatar'] ?? false)) {
                        $user->avatar_path = $newAvatar ?: null;
                    }
                    if (! empty($data['password'])) {
                        $user->password = $data['password'];
                        $user->setRememberToken(Str::random(60));
                        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', session()->getId())->delete();
                    }
                    $user->save();
                    Audit::record('identity.profile_updated', $user, null, ['password_changed' => ! empty($data['password'])]);
                });
            } catch (Throwable $exception) {
                if ($newAvatar) {
                    Storage::disk('local')->delete($newAvatar);
                }
                throw $exception;
            }
            if ($oldAvatar && ($newAvatar || ($data['remove_avatar'] ?? false))) {
                Storage::disk('local')->delete($oldAvatar);
            }
            $request->session()->regenerate();

            return back()->with('status', 'Your profile was updated.');
        }

        return view('identity.profile');
    }

    public function avatar(Request $request): BinaryFileResponse
    {
        $path = $request->user()->avatar_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function activation(Request $request, string $invitation, IdentityOnboarding $onboarding): View|RedirectResponse
    {
        $invite = IdentityInvitation::where('uuid', $invitation)->firstOrFail();
        $member = $onboarding->resolve($invite, (string) $request->query('token'));
        if ($member->user->status === 'active' && ! auth()->check()) {
            return redirect()->guest(route('login'));
        }
        if (auth()->check()) {
            abort_unless(auth()->id() === $member->user_id, 403);
        }

        return view('identity.activation', ['invite' => $invite, 'member' => $member]);
    }

    public function accept(Request $request, string $invitation, IdentityOnboarding $onboarding): RedirectResponse
    {
        $invite = IdentityInvitation::where('uuid', $invitation)->firstOrFail();
        $member = $onboarding->resolve($invite, (string) $request->query('token'));
        $data = $request->validate(['accept' => ['accepted'], 'password' => [$member->user->status === 'pending' ? 'required' : 'nullable', 'confirmed', 'max:255', PasswordRule::min(12)->mixedCase()->numbers()]]);
        $user = $onboarding->accept($invite, (string) $request->query('token'), $data['password'] ?? null);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', 'Invitation accepted. Institutional access becomes available when the institution is active.');
    }

    public function forgot(Request $request): View|RedirectResponse
    {
        if ($request->isMethod('post')) {
            $request->validate(['email' => ['required', 'email', 'max:255']]);
            try {
                Password::sendResetLink(['email' => mb_strtolower($request->input('email')), 'status' => 'active']);
            } catch (Throwable) {
                // The generic response avoids account enumeration or provider-detail leaks.
            }

            return back()->with('status', 'If an active account matches, reset instructions have been prepared. Local development records notifications without inbox delivery.');
        }

        return view('identity.forgot');
    }

    public function resetForm(Request $request, string $token): View
    {
        return view('identity.reset', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'string'], 'email' => ['required', 'email'], 'password' => ['required', 'confirmed', 'max:255', PasswordRule::min(12)->mixedCase()->numbers()]]);
        $status = Password::reset($data + ['status' => 'active'], function (User $user, string $password): void {
            DB::transaction(function () use ($user, $password): void {
                $user->forceFill(['password' => $password])->setRememberToken(Str::random(60));
                $user->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                Audit::record('identity.password_reset', $user, null, [], $user->id);
                event(new PasswordReset($user));
            });
        });

        return $status === Password::PasswordReset ? redirect()->route('login')->with('status', 'Password reset. Log in with your new password.') : back()->withErrors(['email' => 'This reset link is invalid or expired.']);
    }
}

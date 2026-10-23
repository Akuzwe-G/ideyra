<?php

namespace App\Http\Controllers;

use App\Models\AcademicClass;
use App\Models\IdentityInvitation;
use App\Models\Institution;
use App\Models\User;
use App\Support\Audit;
use App\Support\IdentityOnboarding;
use App\Support\InstitutionAccess;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;
use Throwable;

class IdentityController extends Controller
{
    public function dashboard(): View
    {
        $memberships = auth()->user()->memberships()->with('institution', 'role')->where('status', 'active')->whereHas('institution', fn ($query) => $query->where('status', 'active'))->get();

        return view('identity.dashboard', compact('memberships'));
    }

    public function workspace(Institution $institution): View
    {
        InstitutionAccess::authorize($institution);
        $member = InstitutionAccess::membership($institution);
        $classes = AcademicClass::where('institution_id', $institution->id)
            ->when(! InstitutionAccess::allows($institution, 'academic.classes'), fn ($query) => $query->whereIn('id', DB::table('class_assignments')->where('membership_id', $member?->id)->select('academic_class_id')))
            ->orderBy('name')->paginate(15);

        return view('institution.dashboard', compact('institution', 'member', 'classes'));
    }

    public function profile(Request $request): View|RedirectResponse
    {
        if ($request->isMethod('post')) {
            $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'phone' => ['nullable', 'string', 'max:40'],
                'password' => ['nullable', 'confirmed', 'max:255', PasswordRule::min(12)->mixedCase()->numbers()],
                'current_password' => ['required_with:password', 'nullable', 'current_password']]);
            DB::transaction(function () use ($data): void {
                $user = auth()->user();
                $user->forceFill(collect($data)->only(['name', 'phone'])->all());
                if (! empty($data['password'])) {
                    $user->password = $data['password'];
                    $user->setRememberToken(Str::random(60));
                    DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', session()->getId())->delete();
                }
                $user->save();
                Audit::record('identity.profile_updated', $user, null, ['password_changed' => ! empty($data['password'])]);
            });
            $request->session()->regenerate();

            return back()->with('status', 'Your profile was updated.');
        }

        return view('identity.profile');
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

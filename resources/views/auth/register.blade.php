<x-layouts.auth title="Get Started"><div class="auth-panel"><p class="eyebrow">YOUR NEXT CHAPTER</p><h1>Make a little room<br>for possibility.</h1><p>Create your account. Idea, project and opportunity tools are still in development.</p><x-form-errors />
<form method="POST" action="{{ route('register.store') }}">@csrf
<x-ui.input name="name" label="Name" :value="old('name')" :error="$errors->first('name')" autocomplete="name" required maxlength="100" />
<x-ui.input name="email" label="Email" type="email" :value="old('email')" :error="$errors->first('email')" autocomplete="email" required maxlength="255" />
<x-ui.input name="password" label="Password" type="password" hint="At least 12 characters, with upper/lowercase letters and a number." :error="$errors->first('password')" autocomplete="new-password" required minlength="12" maxlength="255" />
<x-ui.input name="password_confirmation" label="Confirm password" type="password" autocomplete="new-password" required minlength="12" maxlength="255" />
<x-ui.button type="submit">Create account →</x-ui.button>
</form><p class="auth-switch">Already have an account? <a href="{{ route('login') }}">Log In</a></p></div></x-layouts.auth>

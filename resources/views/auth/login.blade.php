<x-layouts.auth title="Log In"><div class="auth-panel"><p class="eyebrow">WELCOME BACK</p><h1>A familiar place.<br>A fresh start.</h1><p>Log in to your Ideyra account.</p><x-form-errors />
<form method="POST" action="{{ route('login.store') }}">@csrf
<x-ui.input name="email" label="Email" type="email" :value="old('email')" :error="$errors->first('email')" autocomplete="email" required maxlength="255" />
<x-ui.input name="password" label="Password" type="password" :error="$errors->first('password')" autocomplete="current-password" required maxlength="255" />
<x-ui.button type="submit">Log In <span aria-hidden="true">→</span></x-ui.button>
</form><p class="auth-switch">New to Ideyra? <a href="{{ route('register') }}">Create an account</a></p><p class="form-note">Password recovery is not yet available. Keep your account credentials safe.</p></div></x-layouts.auth>

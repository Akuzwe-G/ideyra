<x-layouts.auth title="Log In">
<div class="auth-panel">
    <p class="eyebrow auth-welcome"><span></span>WELCOME TO IDEYRA</p>
    <h1>Welcome back.</h1><p class="auth-intro">Sign in to your account and pick up where you left off.</p>
    @if(session('status'))<x-ui.alert>{{ session('status') }}</x-ui.alert>@endif
    <x-form-errors />
    <form method="POST" action="{{ route('login.store') }}">@csrf
        <x-ui.input name="email" label="Email" type="email" :value="old('email')" :error="$errors->first('email')" autocomplete="email" required maxlength="255" placeholder="you@institution.edu" />
        <div class="login-password"><x-ui.input name="password" label="Password" type="password" :error="$errors->first('password')" autocomplete="current-password" required maxlength="255" /><button type="button" class="icon-button" data-password-toggle aria-label="Show password" hidden><x-ui.icon name="eye" /></button></div>
        <div class="auth-help"><span><x-ui.icon name="lock-keyhole" />Your private account</span><a href="{{ route('password.request') }}">Forgot password?</a></div>
        <x-ui.button type="submit" class="auth-submit">Log In<x-ui.icon name="arrow-right" /></x-ui.button>
    </form>
    <div class="auth-application"><span>New to Ideyra?</span><p>Accounts are issued by authorised administrators. <a href="{{ route('apply') }}">Introduce your institution<x-ui.icon name="arrow-up-right" /></a></p></div>
</div>
</x-layouts.auth>

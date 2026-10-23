<x-layouts.base title="Identity preview">
<div class="auth-layout"><aside class="auth-story"><x-brand /><div><p class="eyebrow">A place to begin</p><h2>Your next chapter<br>starts with an idea.</h2><p>{{ config('ideyra.tagline') }}</p></div><p>Personal ambition. Shared possibility.</p></aside>
<main id="main" class="auth-content">{{ $slot }}</main></div><x-footer />
</x-layouts.base>

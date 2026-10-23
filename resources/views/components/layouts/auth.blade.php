@props(['title' => 'Identity preview'])
<x-layouts.base :title="$title" description="Secure account access for Ideyra. Register or log in to your account.">
<div class="auth-layout"><aside class="auth-story"><x-brand /><div><p class="eyebrow">A place to begin</p><h2>Your next chapter<br>starts with an idea.</h2><p>{{ config('ideyra.tagline') }}</p></div><a href="{{ route('home') }}">← Back to the website</a></aside>
<main id="main" class="auth-content">{{ $slot }}</main></div><x-footer />
</x-layouts.base>

@props(['title' => 'Account access'])
<x-layouts.base :title="$title" description="Secure access to your administrator-issued Ideyra account.">
<div class="auth-layout refined-auth">
    <aside class="auth-story">
        <div class="auth-brand"><x-brand /></div>
        <div class="auth-story-copy"><span class="auth-kicker">YOUR IDEAS. YOUR NEXT CHAPTER.</span><h2>A place for<br>what comes next<span>.</span></h2><p>One identity. Connected institutions.<br>Space for your potential to grow.</p><div class="auth-brand-art" aria-hidden="true"><span></span><span></span><span></span><span class="auth-art-mark"><img src="{{ config('ideyra.brand.icon') }}" alt="" width="30" height="30"></span></div></div>
        <a class="auth-back" href="{{ route('home') }}"><x-ui.icon name="arrow-left" />Back to the website</a>
    </aside>
    <main id="main" class="auth-content"><div class="auth-form-wrap">{{ $slot }}<footer class="auth-compact-footer"><span>© {{ config('ideyra.copyright_year') }} Ideyra</span><span><x-ui.icon name="shield-check" />Secure account access</span></footer></div></main>
</div>
</x-layouts.base>

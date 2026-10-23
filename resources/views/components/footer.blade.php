<footer class="site-footer">
<div class="container footer-main"><div><x-brand :lazy="true" /><p>A little imagination.<br>A meaningful next step.</p></div>
<nav aria-label="Footer navigation">@foreach(['about' => 'About Us', 'features' => 'Features', 'for-students' => 'For Students', 'for-institutions' => 'For Institutions', 'contact' => 'Contact'] as $name => $label)<a href="{{ route($name) }}">{{ $label }}</a>@endforeach</nav>
<div><p>{{ config('ideyra.tagline') }}</p><button class="text-button" data-install hidden>Install Ideyra</button></div></div>
<div class="container footer-inner"><span>© {{ config('ideyra.copyright_year') }} {{ config('ideyra.name') }} · v{{ config('ideyra.version') }}</span><span>Thoughtfully built for what comes next.</span></div>
</footer>

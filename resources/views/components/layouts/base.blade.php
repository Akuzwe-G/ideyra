@props(['title' => null, 'description' => 'A student-centred home for ideas, projects, opportunities and professional growth. Discover the vision for Ideyra.'])
@php
    $documentTitle = $title ? $title.' | '.config('ideyra.name') : config('ideyra.name').' | '.config('ideyra.tagline');
    $canonical = rtrim(config('app.url'), '/').request()->getPathInfo();
    $privatePage = request()->is('login', 'register', 'dashboard', 'preview/*');
@endphp
<!doctype html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ $description }}">
    <meta name="theme-color" content="{{ config('ideyra.brand.navy') }}">
    <title>{{ $documentTitle }}</title>
    @if($privatePage)<meta name="robots" content="noindex, nofollow">@else<link rel="canonical" href="{{ $canonical }}">@endif
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ config('ideyra.name') }}">
    <meta property="og:title" content="{{ $documentTitle }}">
    <meta property="og:description" content="{{ $description }}">
    @unless($privatePage)<meta property="og:url" content="{{ $canonical }}">@endunless
    @if(is_file(public_path(ltrim(config('ideyra.brand.text'), '/'))))
        <meta property="og:image" content="{{ rtrim(config('app.url'), '/').config('ideyra.brand.text') }}">
        <meta property="og:image:alt" content="Ideyra — Imagine. Build. Achieve.">
    @endif
    <link rel="manifest" href="{{ route('manifest') }}">
    @if(is_file(public_path('assets/brand/favicon-32x32.png')))
        <link rel="icon" href="/assets/brand/favicon.ico" sizes="any">
        <link rel="icon" href="/assets/brand/favicon-32x32.png" type="image/png" sizes="32x32">
        <link rel="icon" href="/assets/brand/favicon-16x16.png" type="image/png" sizes="16x16">
        <link rel="apple-touch-icon" href="/assets/brand/apple-touch-icon.png" sizes="180x180">
    @else
        <link rel="icon" href="data:,">
    @endif
    <style>:root{ @foreach(['navy','blue','teal','sky','white','slate','amber'] as $token) --brand-{{ $token }}:{{ config('ideyra.brand.'.$token) }}; @endforeach }</style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body>
    <a class="skip-link" href="#main">Skip to content</a>
    {{ $slot }}
    <div class="pwa-notice" data-update-notice hidden role="status">
        <span>A new version is ready. Save your work before updating.</span>
        <button class="button button-small" data-update>Update now</button>
        <button class="text-button" data-dismiss-update>Later</button>
    </div>
    <p class="sr-only" role="status" data-connection-status></p>
    @livewireScripts
</body>
</html>

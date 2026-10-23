@props(['title' => null])
<!doctype html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Ideyra is taking shape: a home for student ideas, collaborative projects and future opportunities.">
    <meta name="theme-color" content="{{ config('ideyra.brand.navy') }}">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('ideyra.name') }}</title>
    <link rel="manifest" href="{{ route('manifest') }}">
    @if(is_file(public_path(ltrim(config('ideyra.brand.icon'), '/'))))
        <link rel="icon" href="{{ config('ideyra.brand.icon') }}" type="image/svg+xml">
    @else
        <link rel="icon" href="data:,">
    @endif
    <style>:root{ @foreach(['navy','blue','teal','sky','white','slate'] as $token) --brand-{{ $token }}:{{ config('ideyra.brand.'.$token) }}; @endforeach }</style>
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

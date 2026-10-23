@props(['compact' => false, 'lazy' => false])
@php
    $brandPath = config('ideyra.brand.'.($compact ? 'icon' : 'text'));
    $brandExists = is_file(public_path(ltrim($brandPath, '/')));
    $dimensions = $brandExists ? getimagesize(public_path(ltrim($brandPath, '/'))) : null;
@endphp
<a href="{{ route('home') }}" {{ $attributes->class(['brand', 'brand-compact' => $compact]) }} aria-label="{{ config('ideyra.name') }} home">
@if($brandExists)
<img src="{{ $brandPath }}" alt="{{ $compact ? 'Ideyra icon' : 'Ideyra' }}" width="{{ $dimensions[0] }}" height="{{ $dimensions[1] }}" @if($lazy) loading="lazy" @endif decoding="async">
@else
<span class="brand-placeholder">[{{ config('ideyra.name') }}]</span><span class="brand-note">Official brand asset missing</span>
@endif
</a>

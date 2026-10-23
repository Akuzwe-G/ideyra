@props(['compact' => false])
<a href="{{ route('home') }}" class="brand" aria-label="{{ config('ideyra.name') }} home">
@if(is_file(public_path(ltrim(config('ideyra.brand.'.($compact ? 'icon' : 'text')), '/'))))
<img src="{{ config('ideyra.brand.'.($compact ? 'icon' : 'text')) }}" alt="{{ config('ideyra.name') }}" height="32">
@else
<span class="brand-placeholder" aria-hidden="true">[{{ $compact ? 'I' : config('ideyra.name') }}]</span><span class="brand-note">Brand asset pending</span>
@endif
</a>

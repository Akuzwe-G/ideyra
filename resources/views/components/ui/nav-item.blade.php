@props(['href', 'icon', 'label', 'active' => false])
<a href="{{ $href }}" class="nav-item" @if($active) aria-current="page" @endif aria-label="{{ $label }}" title="{{ $label }}"><x-ui.icon :name="$icon" /><span class="nav-label">{{ $label }}</span><span class="nav-tooltip" aria-hidden="true">{{ $label }}</span></a>

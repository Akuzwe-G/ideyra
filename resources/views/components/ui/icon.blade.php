@props(['name'])
@php
    $allowed = ['x','layout-dashboard','chart-no-axes-combined','files','building-2','users','shield-check','history','settings-2','school','calendar-days','calendar-plus','book-open','graduation-cap','bell','circle-user-round','arrow-up-right','panel-left','search','moon','sun','chevron-down','chevron-right','key-round','log-out','check','check-check','mail','mail-open','arrow-left','arrow-right','eye','lock-keyhole','plus','file-down','circle-help'];
    $allowed = array_merge($allowed, ['sparkles','briefcase-business','file-user','panel-top','notebook-pen']);
    $iconName = in_array($name, $allowed, true) ? $name : 'circle-help';
    $source = file_get_contents(resource_path('views/components/icons/'.$iconName.'.svg'));
    $paths = preg_replace('/^.*?<svg[^>]*>|<\/svg>\s*$/s', '', $source);
@endphp
<svg {{ $attributes->class('icon') }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">{!! $paths !!}</svg>

@props(['title', 'institution' => null])
<x-layouts.base :title="$title" description="Authorised Ideyra workspace.">
<div class="workspace"><aside class="sidebar admin-sidebar"><x-brand /><p class="eyebrow">{{ $institution?->name ?? 'YOUR AUTHORISED CONTEXT' }}</p>
<nav aria-label="Workspace navigation"><a class="nav-item" href="{{ route('dashboard') }}">Personal workspace</a>
@if(\App\Support\InstitutionAccess::system())
@foreach(['system.dashboard' => 'System Administration', 'institutions.index' => 'Institutions', 'system.applications' => 'Applications', 'system.roles' => 'Roles & permissions', 'system.audit' => 'System audit', 'system.settings' => 'Configuration'] as $route => $label)<a class="nav-item" href="{{ route($route) }}" @if(request()->routeIs($route)) aria-current="page" @endif>{{ $label }}</a>@endforeach
@endif
@if($institution)
<a class="nav-item" href="{{ route('institution.workspace', $institution) }}">Institution workspace</a>
@foreach(['institution.profile' => ['institutions.edit', 'Institution profile'], 'members.view' => ['members.index', 'Members'], 'audit.view' => ['institution.audit', 'Institution audit']] as $permission => [$route, $label])
@if(\App\Support\InstitutionAccess::allows($institution, $permission))<a class="nav-item" href="{{ route($route, $institution) }}">{{ $label }}</a>@endif
@endforeach
@foreach(['years' => 'Academic years', 'intakes' => 'Intakes', 'programmes' => 'Programmes', 'classes' => 'Classes'] as $type => $label)
@if(\App\Support\InstitutionAccess::allows($institution, 'academic.'.$type))<a class="nav-item" href="{{ route('academic.index', [$institution, $type]) }}">{{ $label }}</a>@endif
@endforeach
@endif
<a class="nav-item" href="{{ route('profile') }}">My profile</a><a class="nav-item" href="{{ route('home') }}">Public website ↗</a></nav></aside>
<div class="workspace-body"><header class="workspace-header"><span>{{ $institution?->name ?? config('ideyra.name') }} / {{ $title }}</span><form method="POST" action="{{ route('logout') }}">@csrf<x-ui.button type="submit" variant="secondary">Log out</x-ui.button></form></header>
<main id="main" class="workspace-content"><x-ui.heading :title="$title" :eyebrow="$institution ? 'INSTITUTION CONTEXT' : 'IDEYRA'">{{ $institution?->name }}</x-ui.heading>
@if(session('status'))<x-ui.alert>{{ session('status') }}</x-ui.alert>@endif<x-form-errors />{{ $slot }}</main><div class="admin-footer">© {{ config('ideyra.copyright_year') }} {{ config('ideyra.name') }} · v{{ config('ideyra.version') }}</div></div></div>
</x-layouts.base>

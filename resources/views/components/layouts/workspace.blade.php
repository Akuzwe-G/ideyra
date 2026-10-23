@props(['institution' => false])
<x-layouts.base :title="$institution ? 'Institution preview' : 'Personal preview'">
<div class="workspace">
<aside class="sidebar"><x-brand /><x-brand :compact="true" /><p class="eyebrow">{{ $institution ? 'Institution environment' : 'Personal environment' }}</p>
<h2>{{ $institution ? 'Institution preview' : 'Your workspace' }}</h2>
<nav aria-label="Preview navigation"><a class="nav-item active" href="{{ route('preview', $institution ? 'institution' : 'personal') }}" aria-current="page">Overview</a>
@foreach($institution ? ['Programmes', 'Classes & groups', 'Supervision'] : ['Idea Vault', 'Project Studio', 'Opportunities', 'Professional profile'] as $item)
<span class="nav-item unavailable">{{ $item }} <small>Planned</small></span>
@endforeach
<a class="nav-item" href="{{ route('preview', 'components') }}">UI reference</a><a class="nav-item" href="{{ route('home') }}">Back to Ideyra ↗</a></nav><p class="sidebar-note">Layout preview only. No memberships, permissions or personal data are loaded.</p></aside>
<div class="workspace-body"><header class="workspace-header"><span>{{ $institution ? 'Institution identity will appear here' : 'Independent personal workspace' }}</span><details class="account-menu"><summary>Account preview</summary><p>This is a layout example. <a href="{{ route('login') }}">Log In</a> for account access.</p></details></header>
<main id="main" class="workspace-content">{{ $slot }}</main><x-footer /></div></div>
</x-layouts.base>

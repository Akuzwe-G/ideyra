@props(['title', 'institution' => null])
@php
    $identity = auth()->user();
    $systemAccess = \App\Support\InstitutionAccess::system();
    $workspaceMemberships = $identity->memberships()->with('institution', 'role')->where('status', 'active')->whereHas('institution', fn ($query) => $query->where('status', 'active'))->get();
    $contextMember = $institution ? $workspaceMemberships->firstWhere('institution_id', $institution->id) : $workspaceMemberships->first();
    $currentRole = $systemAccess ? 'System Administrator' : ($contextMember?->role->name ?? 'Ideyra member');
    $unreadCount = $identity->unreadNotifications()->count();
@endphp
<x-layouts.base :title="$title" description="Authorised Ideyra workspace." :workspace="true">
<div class="workspace premium-workspace" data-app-shell>
    <button type="button" class="drawer-backdrop" data-drawer-close hidden aria-label="Close navigation overlay"></button>
    <aside id="app-sidebar" class="sidebar admin-sidebar">
        <div class="sidebar-brand"><span class="brand-expanded"><x-brand /></span><span class="brand-collapsed"><x-brand compact /></span><button type="button" class="icon-button drawer-close" data-drawer-close aria-label="Close navigation" hidden><x-ui.icon name="x" /></button></div>
        <div class="sidebar-context"><span class="context-dot"></span><div><strong>{{ $institution?->name ?? ($systemAccess ? 'Platform administration' : 'Your workspace') }}</strong><small>{{ $currentRole }}</small></div></div>
        <nav class="app-navigation" aria-label="Workspace navigation">
            <p class="nav-section-label">Workspace</p>
            <x-ui.nav-item :href="route('ai.index')" icon="sparkles" label="AI · Think further" :active="request()->routeIs('ai.index','ai.conversation','ai.activity')" />
            @if($systemAccess)<x-ui.nav-item :href="route('ai.management')" icon="sparkles" label="AI Management" :active="request()->routeIs('ai.management')" />@endif
            @if($institution && \App\Http\Controllers\AiController::administer($institution))<x-ui.nav-item :href="route('ai.institution',$institution)" icon="sparkles" label="Institution AI controls" :active="request()->routeIs('ai.institution')" />@endif
            <x-ui.nav-item :href="route('career.index')" icon="briefcase-business" label="Career & Profile" :active="request()->routeIs('career.*')" />
            <x-ui.nav-item :href="route('dashboard')" icon="layout-dashboard" label="Personal workspace" :active="request()->routeIs('dashboard')" />
            @foreach(['ideas.index'=>['book-open','Idea Vault','ideas.*'], 'groups.index'=>['users','Academic groups','groups.*'], 'projects.index'=>['files','Project Studio','projects.*'], 'work.mine'=>['calendar-days','My work','work.mine'], 'work.supervision'=>['graduation-cap','Supervision','work.supervision']] as $route=>[$icon,$label,$pattern])
                <x-ui.nav-item :href="route($route)" :icon="$icon" :label="$label" :active="request()->routeIs($pattern)" />
            @endforeach
            @if($systemAccess)
                <p class="nav-section-label">Administration</p>
                @foreach(['system.dashboard' => ['chart-no-axes-combined','Dashboard'], 'system.applications' => ['files','Applications'], 'institutions.index' => ['building-2','Institutions'], 'system.users' => ['users','Users'], 'system.roles' => ['shield-check','Roles & permissions'], 'system.audit' => ['history','Activity logs'], 'system.settings' => ['settings-2','Settings']] as $route => [$icon, $label])
                    <x-ui.nav-item :href="route($route)" :icon="$icon" :label="$label" :active="request()->routeIs($route) || ($route === 'system.applications' && request()->routeIs('system.applications.*')) || ($route === 'system.roles' && request()->routeIs('system.roles.*', 'system.permissions.*')) || ($route === 'institutions.index' && request()->routeIs('institutions.*'))" />
                @endforeach
            @endif
            @if($institution)
                <p class="nav-section-label">Institution</p>
                <x-ui.nav-item :href="route('institution.workspace', $institution)" icon="school" label="Institution workspace" :active="request()->routeIs('institution.workspace')" />
                @foreach(['institution.profile' => ['institutions.edit','building-2','Institution profile'], 'members.view' => ['members.index','users','Members'], 'audit.view' => ['institution.audit','history','Institution activity']] as $permission => [$route, $icon, $label])
                    @if(\App\Support\InstitutionAccess::allows($institution, $permission))<x-ui.nav-item :href="route($route, $institution)" :icon="$icon" :label="$label" :active="request()->routeIs($route) || ($route === 'members.index' && request()->routeIs('members.*'))" />@endif
                @endforeach
                @foreach(['years' => ['calendar-days','Academic years'], 'intakes' => ['calendar-plus','Intakes'], 'programmes' => ['book-open','Programmes'], 'classes' => ['graduation-cap','Classes']] as $type => [$icon,$label])
                    @if(\App\Support\InstitutionAccess::allows($institution, 'academic.'.$type))<x-ui.nav-item :href="route('academic.index', [$institution, $type])" :icon="$icon" :label="$label" :active="request()->routeIs('academic.*') && request()->route('type') === $type" />@endif
                @endforeach
            @elseif(!$systemAccess)
                @foreach($workspaceMemberships as $workspaceMember)<x-ui.nav-item :href="route('institution.workspace', $workspaceMember->institution)" icon="school" :label="$workspaceMember->institution->name" />@endforeach
            @endif
            <p class="nav-section-label">Opportunities & support</p>
            <x-ui.nav-item :href="route('opportunities.index')" icon="graduation-cap" label="Opportunities Hub" :active="request()->routeIs('opportunities.*','opportunity-analytics.*')" />
            <x-ui.nav-item :href="route('opportunity-applications.index')" icon="files" label="Opportunity applications" :active="request()->routeIs('opportunity-applications.*')" />
            <x-ui.nav-item :href="route('sponsorships.index')" icon="users" label="Project support" :active="request()->routeIs('sponsorships.*')" />
            <p class="nav-section-label">Account</p>
            <x-ui.nav-item :href="route('notifications.index')" icon="bell" label="Notifications" :active="request()->routeIs('notifications.*')" />
            <x-ui.nav-item :href="route('profile')" icon="circle-user-round" label="My profile" :active="request()->routeIs('profile')" />
            <x-ui.nav-item :href="route('home')" icon="arrow-up-right" label="Public website" />
        </nav>
        <div class="sidebar-foot"><x-ui.icon name="shield-check" /><span>Secure, institution-led access</span></div>
    </aside>
    <div class="workspace-body">
        <header class="workspace-header app-topbar">
            <div class="topbar-leading"><button type="button" class="icon-button" data-sidebar-toggle aria-label="Toggle navigation" aria-controls="app-sidebar" aria-expanded="true" hidden><x-ui.icon name="panel-left" /></button><div class="topbar-location"><span>{{ $institution?->name ?? 'Ideyra workspace' }}</span><strong>{{ $title }}</strong></div></div>
            <div class="topbar-actions">
                @if($systemAccess)<a class="icon-button topbar-search" href="{{ route('system.applications') }}#application-search" aria-label="Search applications" title="Search applications"><x-ui.icon name="search" /></a>@endif
                <a class="icon-button notification-bell" href="{{ route('notifications.index') }}" aria-label="Notifications{{ $unreadCount ? ', '.$unreadCount.' unread' : '' }}" title="Notifications"><x-ui.icon name="bell" />@if($unreadCount)<span class="notification-count">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>@endif</a>
                <button type="button" class="icon-button" data-theme-toggle aria-label="Switch to dark mode" title="Switch theme" hidden><span class="theme-light-icon"><x-ui.icon name="moon" /></span><span class="theme-dark-icon"><x-ui.icon name="sun" /></span></button>
                <details class="profile-menu" data-profile-menu><summary aria-label="Open account menu"><x-ui.avatar :user="$identity" /><span class="profile-trigger-text"><strong>{{ $identity->name }}</strong><small>{{ $currentRole }}</small></span><x-ui.icon name="chevron-down" /></summary>
                    <div class="profile-dropdown"><div class="profile-dropdown-heading"><strong>{{ $identity->name }}</strong><span>{{ $currentRole }}</span><small>{{ $identity->email }}</small></div><nav aria-label="Account menu">
                        <a href="{{ route('profile') }}"><x-ui.icon name="circle-user-round" />My Profile</a>
                        <a href="{{ route('profile') }}#account-settings"><x-ui.icon name="settings-2" />Account Settings</a>
                        <a href="{{ route('profile') }}#change-password"><x-ui.icon name="key-round" />Change Password</a>
                    </nav><form method="POST" action="{{ route('logout') }}">@csrf<button type="submit"><x-ui.icon name="log-out" />Log out</button></form></div>
                </details>
            </div>
        </header>
        <main id="main" class="workspace-content"><div class="workspace-page-header"><div><p class="breadcrumbs"><a href="{{ $systemAccess ? route('system.dashboard') : route('dashboard') }}">Workspace</a><x-ui.icon name="chevron-right" /><span>{{ $title }}</span></p><h1>{{ $title }}</h1>@if($institution)<p>{{ $institution->name }}</p>@endif</div><span class="role-chip"><x-ui.icon name="shield-check" />{{ $currentRole }}</span></div>
            @if(session('status'))<x-ui.alert>{{ session('status') }}</x-ui.alert>@endif<x-form-errors />{{ $slot }}
        </main>
        <footer class="admin-footer"><span>© {{ config('ideyra.copyright_year') }} Ideyra</span><span>Imagine. Build. Achieve.</span></footer>
    </div>
    <dialog class="confirm-dialog" data-confirm-dialog aria-labelledby="confirm-title"><form method="dialog"><div class="dialog-icon"><x-ui.icon name="shield-check" /></div><h2 id="confirm-title">Confirm this change</h2><p data-confirm-message></p><div class="button-row"><button class="button button-secondary" value="cancel">Go back</button><button class="button" value="confirm">Confirm change</button></div></form></dialog>
</div>
</x-layouts.base>

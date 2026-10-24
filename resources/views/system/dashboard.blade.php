<x-layouts.admin title="System Administration">
<div class="page-toolbar"><div><h2>Your platform, at a glance</h2><p>A clear view of institution onboarding, application review and the people joining Ideyra.</p></div><a class="button button-secondary" href="{{ route('system.audit') }}"><x-ui.icon name="history" />Activity logs</a></div>
<p class="eyebrow">PLATFORM TOTALS · ALL TIME</p>
<div class="overview-grid">
@foreach($totals as $label => $count)
@php
[$icon,$href] = match($label) {
    'Total institutions' => ['building-2', route('institutions.index')],
    'Active institutions' => ['school', route('institutions.index', ['status'=>'active'])],
    'Total users' => ['users', route('system.users')],
    'Total applications' => ['files', route('system.applications')],
    'Pending applications' => ['history', route('system.applications', ['queue'=>'pending'])],
    default => ['shield-check', route('system.applications', ['status'=>'approved'])],
};
@endphp
<a class="overview-card" href="{{ $href }}"><x-ui.icon :name="$icon" /><div><small>{{ $label }}</small><strong>{{ number_format($count) }}</strong></div></a>
@endforeach</div>
<div class="attention-panel"><div><p><strong>{{ $totals['Pending applications'] }} applications awaiting review</strong> · {{ $pendingInstitutions }} institutions awaiting onboarding</p><small>Approval and institution activation remain separate, authorised steps.</small></div><a class="button button-secondary" href="{{ route('system.applications', ['queue'=>'pending']) }}">Review pending<x-ui.icon name="arrow-right" /></a></div>
<div class="quick-actions"><x-ui.button :href="route('institutions.create')"><x-ui.icon name="plus" />Create institution</x-ui.button><x-ui.button :href="route('system.applications')" variant="secondary"><x-ui.icon name="files" />Manage applications</x-ui.button><x-ui.button :href="route('system.roles')" variant="secondary"><x-ui.icon name="shield-check" />Roles & permissions</x-ui.button></div>
<x-analytics.dashboard :analytics="$analytics" :filters="$filters" :show-kpis="false" />
<section class="card analytics-recent"><h2>Recently onboarded institutions</h2><div class="table-scroll"><table><thead><tr><th>Institution</th><th>Current status</th><th>Activated</th></tr></thead><tbody>@forelse($recentOnboarded as $recent)<tr><td><a href="{{ route('institutions.edit', $recent) }}">{{ $recent->name }}</a></td><td><x-ui.status :status="$recent->status" /></td><td>{{ $recent->approved_at->format('d M Y') }}</td></tr>@empty<tr><td colspan="3">No institutions have completed activation yet.</td></tr>@endforelse</tbody></table></div></section>
</x-layouts.admin>

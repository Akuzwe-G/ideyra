@props(['analytics', 'filters', 'institution' => null, 'showKpis' => true])
<section data-analytics data-theme="system" aria-label="Dashboard analytics">
    <form method="GET" class="analytics-filters" data-analytics-filters>
        <label>From<input type="date" name="from" value="{{ $filters['from'] }}" required></label>
        <label>To<input type="date" name="to" value="{{ $filters['to'] }}" required></label>
        <label>Time grouping<select name="group">@foreach(['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $value => $label)<option value="{{ $value }}" @selected($filters['group'] === $value)>{{ $label }}</option>@endforeach</select></label>
        <button class="button button-primary" type="submit">Apply filters</button>
    </form>
    <p class="analytics-context">{{ $filters['from'] }} to {{ $filters['to'] }} · {{ config('app.timezone') }} · {{ ucfirst($filters['group']) }} periods.
        {{ $institution ? 'Membership cohort: dates filter when members joined. Enrolment and composition reflect their current active status; academic groups may overlap.' : 'Dates filter record creation. Status charts show the current status of those records.' }}
    </p>
    <p data-analytics-status role="status" class="analytics-status">Charts enhance the summaries and data tables below.</p>
    @if($showKpis)<div class="analytics-kpis">@foreach($analytics['kpis'] as $label => $count)<article class="card"><p>{{ $label }}</p><strong>{{ number_format($count) }}</strong></article>@endforeach</div>@endif
    <div class="analytics-grid">
    @foreach($analytics['charts'] as $chart)
        @php($total = array_sum($chart['values']))
        <article class="card analytics-chart" data-chart="{{ json_encode($chart) }}" aria-labelledby="chart-title-{{ $chart['key'] }}">
            <div class="analytics-chart-heading"><div><p class="eyebrow">{{ $chart['type'] === 'line' ? 'TREND' : ($chart['type'] === 'doughnut' ? 'COMPOSITION' : 'DISTRIBUTION') }}</p><h2 id="chart-title-{{ $chart['key'] }}">{{ $chart['title'] }}</h2></div><span class="analytics-total">{{ number_format($total) }}<small>{{ $chart['unit'] }}</small></span></div>
            <p id="chart-note-{{ $chart['key'] }}" class="analytics-note">{{ $chart['note'] }}</p>
            @if($total > 0)
                <div class="analytics-canvas" hidden><canvas role="img" tabindex="0" aria-label="{{ $chart['title'] }}. Use arrow keys to inspect values; see the data table for all values." aria-describedby="chart-note-{{ $chart['key'] }}">{{ $chart['title'] }}: {{ $total }} {{ $chart['unit'] }}. Complete data follows.</canvas></div>
                <div class="analytics-legend" aria-label="{{ $chart['title'] }} legend"></div>
            @else
                <p class="analytics-empty">No matching data in this period. Try a wider date range{{ $institution ? ' or add members and class assignments.' : '.' }}</p>
            @endif
            <div class="analytics-actions"><button type="button" class="button button-secondary" data-chart-png hidden>Export PNG<span class="sr-only"> — {{ $chart['title'] }}</span></button><a class="button button-secondary" href="{{ $institution ? route('institution.analytics.csv', [$institution, $chart['key']] + $filters) : route('system.analytics.csv', ['chart' => $chart['key']] + $filters) }}">Export CSV<span class="sr-only"> — {{ $chart['title'] }}</span></a></div>
            <details><summary>View data table — {{ $chart['title'] }}</summary><div class="table-scroll analytics-table" tabindex="0" role="region" aria-label="{{ $chart['title'] }} data"><table><caption>{{ $chart['title'] }} · {{ $filters['from'] }} to {{ $filters['to'] }}</caption><thead><tr><th scope="col">{{ $chart['type'] === 'line' ? 'Period starting' : 'Category' }}</th><th scope="col">{{ $chart['unit'] }}</th>@if($chart['type'] === 'doughnut')<th scope="col">Share</th>@endif</tr></thead><tbody>@forelse($chart['labels'] as $index => $label)<tr><th scope="row">{{ $label }}</th><td>{{ $chart['values'][$index] }}</td>@if($chart['type'] === 'doughnut')<td>{{ $total ? number_format($chart['values'][$index] / $total * 100, 1) : '0.0' }}%</td>@endif</tr>@empty<tr><td colspan="3">No matching records.</td></tr>@endforelse</tbody></table></div></details>
        </article>
    @endforeach
    </div>
    <div class="analytics-grid analytics-recent">
        @unless($institution)
        <article class="card"><h2>Recent applications</h2><ul>@forelse($analytics['recentApplications'] as $application)<li><a href="{{ route('system.applications.show', $application->uuid) }}">{{ $application->reference }}</a><span>{{ str_replace('_', ' ', $application->status) }}</span></li>@empty<li>No applications in this period.</li>@endforelse</ul></article>
        <article class="card"><h2>Recent institutions</h2><ul>@forelse($analytics['recentInstitutions'] as $item)<li><a href="{{ route('institutions.edit', $item->id) }}">{{ $item->name }}</a><span>{{ str_replace('_', ' ', $item->status) }}</span></li>@empty<li>No institutions in this period.</li>@endforelse</ul></article>
        @endunless
        <article class="card"><h2>Recent activity</h2><ul>@forelse($analytics['activities'] as $activity)<li><span>{{ str_replace(['.', '_'], ' ', $activity->action) }}</span><time>{{ $activity->created_at }}</time></li>@empty<li>No recorded activity in this period.</li>@endforelse</ul></article>
    </div>
</section>

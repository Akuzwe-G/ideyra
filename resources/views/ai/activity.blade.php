<x-layouts.admin title="AI activity & quotas" :institution="$institution">
<section class="card"><h2>{{ ucfirst($scope) }} AI activity</h2><p>Usage helps you manage optional assistance. It does not measure intelligence, productivity or academic success. Metadata only; private conversations are excluded.</p><p>Today: {{ $used }} / {{ $limit }} requests · {{ max(0,$limit-$used) }} remaining</p><progress max="{{ max(1,$limit) }}" value="{{ min($used,$limit) }}">{{ $limit?round($used/$limit*100):0 }}%</progress></section>
<x-analytics.dashboard :analytics="$analytics" :filters="$filters" :institution="$institution" :personal="true" :ai-mode="true" :scope="$scope" />
</x-layouts.admin>


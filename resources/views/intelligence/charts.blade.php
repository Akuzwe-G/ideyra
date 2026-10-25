<div class="analytics-grid">
@foreach($charts as $chart)
<section class="card analytics-chart" data-chart='@json($chart)'>
<div class="analytics-chart-heading"><h2>{{ $chart['title'] }}</h2><div class="button-row"><button class="button button-small button-secondary" type="button" data-chart-png hidden>PNG</button>
@if(($allowExport??false))<a class="button button-small button-secondary" href="{{ route('intelligence.csv',array_merge($filters,['chart'=>$chart['key']])) }}">CSV</a>@endif
</div></div><p>{{ $chart['note'] }}</p>
@if($chart['protected'])<p class="intelligence-notice">Small-group protection applies. Protected values are omitted from the visual and every export.</p>@endif
@if(count(array_filter($chart['values'],fn($v)=>$v!==null && $v!=0)))
<div class="analytics-canvas" hidden><canvas tabindex="0" role="img" aria-label="{{ $chart['title'] }}. Use arrow keys to explore values; the full data table follows."></canvas></div>
@else<p class="analytics-empty">{{ $chart['protected']?'This breakdown is protected to preserve privacy.':'No recorded activity matches these filters. Adjust the period or check source records.' }}</p>@endif
<div class="analytics-legend" aria-label="{{ $chart['title'] }} legend"></div>
@if(count($chart['labels'])>15 && $chart['type']==='bar')<p>Chart displays the first 15 categories; the table includes all returned categories (maximum 100).</p>@endif
<details class="intelligence-data"><summary>Accessible data table · {{ count($chart['labels']) }} rows</summary><div class="table-scroll"><table><caption>{{ $chart['title'] }}</caption><thead><tr><th scope="col">Category</th><th scope="col">{{ $chart['unit'] }}</th></tr></thead><tbody>@forelse($chart['labels'] as $i=>$label)<tr><th scope="row">@if(!empty($chart['links'][$i]))<a href="{{ $chart['links'][$i] }}">{{ $label }}</a>@else{{ $label }}@endif</th><td>{{ $chart['values'][$i]===null?'Protected / unavailable':number_format($chart['values'][$i],is_float($chart['values'][$i])?2:0) }}</td></tr>@empty<tr><td colspan="2">No matching records.</td></tr>@endforelse</tbody></table></div></details>
</section>
@endforeach
</div>

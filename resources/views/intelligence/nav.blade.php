<nav class="intelligence-tabs" aria-label="Intelligence sections">
@if($context['platform'] ? \App\Support\NetworkAccess::platform(auth()->user(),'innovation-analytics.view_platform') : ($institution && \App\Support\NetworkAccess::can(auth()->user(),$institution,'innovation-analytics.view')))<a href="{{ route('intelligence.index',array_replace($filters,['section'=>'network'])) }}" @if(($filters['section']??'')==='network') aria-current="page" @endif>Innovation network</a>@endif
@foreach(['overview'=>'Overview','projects'=>'Projects','participation'=>'Participation','programmes'=>'Programmes','opportunities'=>'Opportunities','career'=>'Career adoption','achievements'=>'Verified achievements','ai'=>'AI usage'] as $key=>$label)
<a href="{{ route('intelligence.index',array_replace($filters,['section'=>$key])) }}" @if(($filters['section']??'')===$key) aria-current="page" @endif>{{ $label }}</a>
@endforeach
@if(\App\Support\IntelligenceAccess::permits(auth()->user(),$filters,'data_quality.view'))<a href="{{ route('intelligence.index',array_replace($filters,['section'=>'quality'])) }}" @if(($filters['section']??'')==='quality') aria-current="page" @endif>Data quality</a>@endif
@if($institution && \App\Support\IntelligenceAccess::kpi(auth()->user(),$institution->id,'kpis.view'))<a href="{{ route('kpis.index',$institution) }}">KPI scorecard</a>@endif
@if(\App\Support\IntelligenceAccess::permits(auth()->user(),$filters,'reports.generate'))<a href="{{ route('reports.index',$filters) }}">Report Centre</a>@endif
</nav>

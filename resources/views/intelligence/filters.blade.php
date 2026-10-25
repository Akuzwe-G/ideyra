<form method="GET" action="{{ $filterAction??route('intelligence.index') }}" class="analytics-filters intelligence-filter">
<input type="hidden" name="section" value="{{ $filters['section']??'overview' }}">
<label>Institution<select name="institution_id"><option value="">All authorised institutions</option>@foreach($options['institutions'] as $item)<option value="{{ $item->id }}" @selected(($filters['institution_id']??'')==$item->id)>{{ $item->name }}</option>@endforeach</select></label>
<label>From<input type="date" name="from" value="{{ $filters['from'] }}" required></label><label>To<input type="date" name="to" value="{{ $filters['to'] }}" required></label>
<label>Group by<select name="group">@foreach(['daily','weekly','monthly'] as $value)<option @selected($filters['group']===$value)>{{ $value }}</option>@endforeach</select></label>
<label>Period comparison<select name="compare"><option value="0">Current period only</option><option value="1" @selected($filters['compare'])>Previous equal period</option></select></label>
@php($advanced = collect(['academic_year_id','intake_id','programme_id','academic_class_id','project_group_id','supervisor_id','status','project_category','opportunity_category_id'])->contains(fn($key)=>!empty($filters[$key])) || $filters['cohort_days']!==30)
<details class="intelligence-advanced" @if($advanced) open @endif><summary>Academic & activity filters</summary><div class="intelligence-advanced-grid">
@if($institution)
@foreach(['academic_year_id'=>['academic_years','Academic year'],'intake_id'=>['intakes','Intake'],'programme_id'=>['programmes','Programme'],'academic_class_id'=>['academic_classes','Class'],'project_group_id'=>['project_groups','Project group'],'supervisor_id'=>['supervisors','Supervisor']] as $name=>[$source,$label])
<label>{{ $label }}<select name="{{ $name }}"><option value="">All authorised</option>@foreach($options[$source] as $item)<option value="{{ $item->id }}" @selected(($filters[$name]??'')==$item->id)>{{ $item->name }}</option>@endforeach</select></label>
@endforeach
@endif
<label>Project status<select name="status"><option value="">All statuses</option>@foreach(['draft','pending_approval','approved','in_progress','on_hold','completed','cancelled','archived'] as $value)<option value="{{ $value }}" @selected(($filters['status']??'')===$value)>{{ str($value)->replace('_',' ')->title() }}</option>@endforeach</select></label>
<label>Project category<input name="project_category" value="{{ $filters['project_category']??'' }}" maxlength="100" placeholder="Any category"></label>
<label>Opportunity category<select name="opportunity_category_id"><option value="">All categories</option>@foreach($options['categories'] as $item)<option value="{{ $item->id }}" @selected(($filters['opportunity_category_id']??'')==$item->id)>{{ $item->name }}</option>@endforeach</select></label>
<label>Idea maturity (days)<input type="number" name="cohort_days" min="0" max="365" value="{{ $filters['cohort_days'] }}"></label>
</div></details>
<button type="submit" class="button">Apply filters</button>
<p class="intelligence-filter-note">Dates use {{ config('intelligence.timezone') }}. When changing institution, clear academic filters. Academic filters constrain cohort-based metrics; publication and AI metadata retain their explicitly stated scope.</p>
</form>

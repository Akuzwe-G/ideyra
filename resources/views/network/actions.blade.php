<details class="card"><summary>{{ $actionTitle??'Record a workflow decision' }}</summary><form class="innovation-form" method="POST" action="{{ $actionUrl }}">@csrf
<label>Next status<select name="{{ $actionField??'status' }}" required>@foreach($statuses as $status)<option value="{{ $status }}">{{ ucfirst(str_replace('_',' ',$status)) }}</option>@endforeach</select></label>
<label>Reason<textarea name="reason" required maxlength="3000" rows="3"></textarea></label>@include('network.confirm')<button class="button">Record decision</button></form></details>

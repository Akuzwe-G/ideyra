<nav class="innovation-tabs network-nav" aria-label="Innovation network">
@foreach(['network.showcase'=>'Showcase','network.challenges'=>'Challenges','network.events'=>'Events & exhibitions'] as $r=>$label)<a href="{{ route($r) }}" @if(request()->routeIs($r,$r.'.show')) aria-current="page" @endif>{{ $label }}</a>@endforeach
@auth
@unless(\App\Support\InstitutionAccess::system())
@foreach(['network.publications.mine'=>'My publications','network.publications.reviews'=>'Review queue','network.requests'=>'Requests','network.workspaces'=>'Collaborations','network.judges'=>'Judging','network.recognitions'=>'Recognitions'] as $r=>$label)<a href="{{ route($r) }}" @if(request()->routeIs($r)) aria-current="page" @endif>{{ $label }}</a>@endforeach
@endunless
@php($networkMemberships=\App\Support\InnovationAccess::memberships(auth()->user()))
@if(\App\Support\NetworkAccess::platform(auth()->user()) || $networkMemberships->contains(fn($m)=>\App\Support\NetworkAccess::can(auth()->user(),$m->institution,'moderation.review')))<a href="{{ route('network.moderation') }}">Moderation</a>@endif
@foreach($networkMemberships as $m)@if(\App\Support\NetworkAccess::can(auth()->user(),$m->institution,'network.policy'))<a href="{{ route('network.policy',$m->institution) }}">{{ $m->institution->name }} policy</a>@endif@endforeach
@endauth
</nav>

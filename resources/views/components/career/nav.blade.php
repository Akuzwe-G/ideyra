<nav class="innovation-tabs" aria-label="Career navigation">
<a href="{{ route('career.index') }}" @if(request()->routeIs('career.index') && !request('tab')) aria-current="page" @endif>Career dashboard</a>
<a href="{{ route('career.index',['tab'=>'profile']) }}">Professional profile</a>
<a href="{{ route('career.index',['tab'=>'project']) }}">Project showcase</a>
<a href="{{ route('career.verifications') }}">Verification</a>
<a href="{{ route('career.cvs.index') }}">My CVs</a>
<a href="{{ route('career.portfolio') }}">Portfolio builder</a>
</nav>

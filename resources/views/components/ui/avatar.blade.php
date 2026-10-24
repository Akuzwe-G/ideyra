@props(['user'])
<span {{ $attributes->class('user-avatar') }}>@if($user->avatar_path && $user->is(auth()->user()) && \Illuminate\Support\Facades\Storage::disk('local')->exists($user->avatar_path))<img src="{{ route('profile.avatar', ['v' => $user->updated_at->timestamp]) }}" alt="" width="40" height="40">@else<span aria-hidden="true">{{ $user->initials() }}</span>@endif</span>

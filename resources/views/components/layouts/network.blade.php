@props(['title'])
<x-dynamic-component :component="auth()->check() ? 'layouts.admin' : 'layouts.public'" :title="$title">
<div class="network-shell {{ auth()->guest() ? 'container network-guest' : '' }}">
@guest<h1>{{ $title }}</h1>@if(session('status'))<x-ui.alert>{{ session('status') }}</x-ui.alert>@endif<x-form-errors />@endguest
@include('network.nav')
{{ $slot }}
</div>
</x-dynamic-component>

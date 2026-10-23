@props(['title', 'eyebrow' => null])
<div class="page-heading">@if($eyebrow)<p class="eyebrow">{{ $eyebrow }}</p>@endif<h1>{{ $title }}</h1><p>{{ $slot }}</p></div>

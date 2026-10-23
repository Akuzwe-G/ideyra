@props(['number', 'title', 'status' => 'Planned'])
<article class="feature-card"><div class="feature-top"><span class="feature-number">{{ $number }}</span><x-ui.badge>{{ $status }}</x-ui.badge></div><div class="feature-symbol" aria-hidden="true">↗</div><h3>{{ $title }}</h3><p>{{ $slot }}</p></article>

<x-layouts.base :title="$title ?? null">
<header class="site-header"><div class="container header-inner"><x-brand />
<nav aria-label="Main navigation"><a href="{{ route('home') }}#possibilities">The possibilities</a><a href="{{ route('home') }}#institutions">For institutions</a><a class="button button-small" href="{{ route('home') }}#next">What’s next <span aria-hidden="true">↗</span></a></nav></div></header>
<main id="main">{{ $slot }}</main>
<x-footer />
</x-layouts.base>

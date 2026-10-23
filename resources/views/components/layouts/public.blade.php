@props(['title' => null, 'description' => 'A student-centred home for ideas, projects, opportunities and professional growth. Discover the vision for Ideyra.'])
<x-layouts.base :title="$title" :description="$description">
<x-public-header />
<main id="main">{{ $slot }}</main>
<x-footer />
</x-layouts.base>

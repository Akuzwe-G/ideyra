@props(['title' => 'Your next chapter starts here.', 'institution' => false])
<section class="container section"><div class="marketing-cta"><div><p class="eyebrow">IMAGINE. BUILD. ACHIEVE.</p><h2>{{ $title }}</h2><p>{{ $slot }}</p></div><x-ui.button :href="route($institution ? 'contact' : 'register')">{{ $institution ? 'Talk to us' : 'Create your account' }} ↗</x-ui.button></div></section>

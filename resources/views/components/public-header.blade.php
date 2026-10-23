<header class="site-header marketing-header">
<div class="container header-inner">
    <x-brand />
    <button class="menu-toggle button button-secondary button-small" type="button" aria-expanded="false" aria-controls="public-navigation" data-menu-toggle hidden>Menu <span aria-hidden="true">☰</span></button>
    <nav id="public-navigation" aria-label="Main navigation" class="public-navigation">
        @foreach(['home' => 'Home', 'about' => 'About', 'features' => 'Features', 'for-students' => 'For Students', 'for-institutions' => 'For Institutions', 'contact' => 'Contact'] as $name => $label)
            <a href="{{ route($name) }}" @if(request()->routeIs($name)) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
        <div class="nav-actions">
            @auth
                <a class="button button-small" href="{{ route('dashboard') }}">Your account ↗</a>
            @else
                <a href="{{ route('login') }}">Log In</a>
                <a class="button button-small" href="{{ route('register') }}">Get Started ↗</a>
            @endauth
        </div>
    </nav>
</div>
</header>

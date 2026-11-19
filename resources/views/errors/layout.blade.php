<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('code') · @yield('title') · PATH</title>
    <link rel="stylesheet" href="{{ asset('css/error-pages.css') }}?v={{ filemtime(public_path('css/error-pages.css')) }}">
</head>
<body>
<main class="error-shell">
    <section class="error-card" aria-labelledby="errorTitle">
        <a class="error-brand" href="{{ route('public.proposals') }}" aria-label="PATH public directory">
            <img src="{{ asset('images/path-logo.png') }}" alt="">
            <span><b>PATH</b><small>Proposal Administration &amp; Tracking Hub</small></span>
        </a>
        <div class="error-code" aria-hidden="true">@yield('code')</div>
        <div class="error-content">
            <p class="error-kicker">@yield('kicker', 'Something interrupted your request')</p>
            <h1 id="errorTitle">@yield('title')</h1>
            <p class="error-message">@yield('message')</p>
            <div class="error-actions">
                <a class="error-primary" href="{{ auth()->check() ? route(auth()->user()->homeRouteName()) : route('public.proposals') }}">{{ auth()->check() ? 'Return to PATH' : 'Open public directory' }}</a>
                @guest<a class="error-secondary" href="{{ route('login') }}">Staff sign in</a>@endguest
            </div>
        </div>
        <p class="error-help">If the problem continues, record what you were doing and contact the PATH administrator.</p>
    </section>
</main>
</body>
</html>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') · PATH</title>
    <link rel="stylesheet" href="{{ asset('css/path.css') }}">
    <link rel="stylesheet" href="{{ asset('css/path-overrides.css') }}?v={{ filemtime(public_path('css/path-overrides.css')) }}">
</head>
<body class="legal-page">
    <a class="skip-link" href="#mainContent">Skip to main content</a>
    <header class="legal-topbar">
        <a class="legal-brand" href="{{ auth()->check() ? route(auth()->user()->homeRouteName()) : route('login') }}">
            <img src="{{ asset('images/path-logo.png') }}" alt="PATH logo">
            <span><b>PATH</b><small>Proposal Administration &amp; Tracking Hub</small></span>
        </a>
        <a class="btn ghost" href="{{ auth()->check() ? route(auth()->user()->homeRouteName()) : route('login') }}">{{ auth()->check() ? 'Return to PATH' : 'Return to sign in' }}</a>
    </header>
    <main id="mainContent" class="legal-main" tabindex="-1">
        <article class="legal-document">
            <div class="legal-document-head">
                <span class="eyebrow">LEGAL &amp; PRIVACY</span>
                <h1>@yield('heading')</h1>
                <p>Effective date: {{ config('compliance.effective_date') }}</p>
                <span class="legal-review-badge">{{ config('compliance.policy_status') }}</span>
            </div>
            @yield('content')
        </article>
    </main>
    <footer class="legal-footer">
        <a href="{{ route('legal.privacy') }}">Privacy Policy</a>
        <a href="{{ route('legal.terms') }}">Terms and Conditions</a>
        <a href="{{ route('legal.cookies') }}">Cookie Policy</a>
        <a href="{{ route('legal.accessibility') }}">Accessibility</a>
    </footer>
</body>
</html>

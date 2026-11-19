<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Funded projects &amp; proponents · PATH</title>
    <link rel="stylesheet" href="{{ asset('css/path.css') }}">
    <link rel="stylesheet" href="{{ asset('css/path-overrides.css') }}">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
    <link rel="stylesheet" href="{{ asset('css/public-directory.css') }}?v={{ filemtime(public_path('css/public-directory.css')) }}">
</head>
<body class="public-directory">
<a class="skip-link" href="#mainContent">Skip to main content</a>

<main id="mainContent" class="directory-main" tabindex="-1">
    <section class="directory-intro">
        <div><span class="eyebrow">DOST · PUBLIC DIRECTORY</span>
        <h1>The proponent <em>atlas.</em></h1>
        <p>A public guide to funded innovation in Central Visayas.</p></div>
        <dl class="directory-stats" aria-label="Current search summary">
            <div><dt>Matching projects</dt><dd>{{ number_format($proposals->total()) }}</dd></div>
            <div><dt>Projects with locations</dt><dd>{{ number_format(count($locations)) }}</dd></div>
            <div><dt>Matching provinces</dt><dd>{{ count($regions) }}</dd></div>
        </dl>
    </section>
    @if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif

 
    <div class="directory-explorer">
    <button type="button" id="directoryListToggle" class="directory-list-toggle" aria-expanded="true" aria-controls="directoryResults" hidden>Hide list</button>
    <section id="directoryResults" class="directory-results" aria-labelledby="resultsHeading">
<header class="legal-topbar">
    <a class="legal-brand" href="{{ route('public.proposals') }}">
        <img src="{{ asset('images/path-logo.png') }}" alt="PATH logo">
        <span><b>PATH</b><small>Proposal Administration &amp; Tracking Hub</small></span>
    </a>
    <nav class="directory-nav" aria-label="Public navigation">
        <a href="#resultsHeading">Directory</a><a href="#mapHeading">Map</a>
        @auth
            <a class="btn ghost" href="{{ route(auth()->user()->homeRouteName()) }}">Logged in as {{ auth()->user()->full_name }} <span aria-hidden="true">→</span></a>
        @else
            <button class="btn ghost" id="staffSignInButton" type="button" aria-haspopup="dialog" aria-controls="staffLoginDialog">Staff sign in <span aria-hidden="true">→</span></button>
        @endauth
    </nav>
</header>
    <button type="button" id="directoryFilterToggle" class="directory-filter-toggle" aria-expanded="true" aria-controls="directoryFilters" hidden>Search and filters</button>
    <form id="directoryFilters" method="GET" action="{{ route('public.proposals') }}" class="directory-filters" role="search">
        <label>Find a proponent
            <input type="search" name="search" maxlength="120" value="{{ $filters['search'] ?? '' }}" placeholder="Search by proponent name">
        </label>
        <label>Province
            <select name="province"><option value="">All provinces</option>
                @foreach($provinces as $province)<option value="{{ $province }}" @selected(($filters['province'] ?? '') === $province)>{{ $province }}</option>@endforeach
            </select>
        </label>
        <label>Program
            <select name="sector"><option value="">All sectors</option>
                @foreach($sectors as $sector)<option value="{{ $sector }}" @selected(($filters['sector'] ?? '') === $sector)>{{ $sector }}</option>@endforeach
            </select>
        </label>
        <label class="directory-industry-filter">Industry sector
            <select name="industry"><option value="">All industries</option>
                @foreach($industryOptions as $industryName)<option value="{{ $industryName }}" @selected(($filters['industry'] ?? '') === $industryName)>{{ $industryName }}</option>@endforeach
                <option value="__unclassified__" @selected(($filters['industry'] ?? '') === '__unclassified__')>Not classified</option>
            </select>
        </label>
        
        @include('partials.date-range-filter', ['dateBasis' => 'Funding date', 'overridesYear' => false])
        <button class="btn primary" type="submit">Find proponents</button>
        <a class="btn ghost" href="{{ route('public.proposals') }}">Reset</a>
    </form>
        <div class="directory-section-heading"><div><span class="eyebrow">FIND A PROPONENT</span><h2 id="resultsHeading" tabindex="-1">Explore proponents</h2></div>
            @if($proposals->count())<span>Showing {{ $proposals->firstItem() }}–{{ $proposals->lastItem() }} of {{ number_format($proposals->total()) }}</span>@endif
        </div>
       <p class="directory-list-help">One entry per funded project. Select “Show on map” to locate it.</p>
        @if(!empty($filters['search']) || !empty($filters['province']) || !empty($filters['sector']))
            <p class="directory-active-filters">Filtered by: {{ implode(' · ', array_filter([$filters['search'] ?? '', $filters['province'] ?? '', $filters['sector'] ?? ''])) }} <a href="{{ route('public.proposals') }}">Clear all</a></p>
        @endif
        <div class="directory-overview" aria-label="Matching results"><span><b>{{ number_format($proposals->total()) }}</b> funded projects</span><span><b>{{ count($regions) }}</b> provinces</span><span><b>{{ count($locations) }}</b> mapped</span></div>
        <details class="directory-about"><summary>About this directory</summary><p>Explore funded projects and their proponents. Industry icons describe the business sector, and pin borders show the province. Select a numbered group to zoom in.</p><p>Only locations explicitly checked by staff are labeled verified. Other pins may be approximate. Projects without coordinates remain in the list unless searching a map area.</p><p>Highlights and photos are reviewed before publication. Use Report incorrect information in a map popup to flag a problem. Reports are private and do not directly change project records.</p></details>
        @if(auth()->user()?->isAdmin())<a class="directory-review-link" href="{{ route('public-directory.review') }}">Review reports &amp; public highlights</a>@endif
        <div class="directory-grid" tabindex="0" role="region" aria-label="Funded project results">
            @forelse($proposals as $proposal)
                <article class="directory-card" @if(in_array($mapKeys[$loop->index], $mappedKeys, true)) data-map-project="{{ $mapKeys[$loop->index] }}" @endif>
                    <div class="directory-card-top"><div class="directory-tags"><span data-province-color="{{ $proposal->province }}">{{ $proposal->province }}</span><span>{{ $proposal->sector }}</span></div></div>
                    <h3>{{ $proposal->proponent }}</h3>
                    <p class="directory-industry"><i class="fa-solid {{ \App\Support\IndustrySectorIcon::forName($proposal->industrySector?->name) }}" aria-hidden="true"></i> <span>Industry:</span> {{ $proposal->industrySector?->name ?? 'Not classified' }}</p>
                    <p class="directory-address"><span>Address</span>{{ filled($proposal->address) ? $proposal->address : 'Address not provided' }}</p>
                    @php($publicLocation = collect($locations)->firstWhere('map_key', $mapKeys[$loop->index]))
                    <small class="directory-location-label">{{ $publicLocation['location_accuracy'] ?? 'No map location saved' }}</small>
                    @if($proposal->publicProfile?->summary || $proposal->publicProfile?->photo_path)
                    <details class="directory-highlight"><summary>Project highlight</summary>
                        @if($proposal->publicProfile->photo_path)<img loading="lazy" src="{{ route('public.projects.photo', ['key' => $mapKeys[$loop->index]]) }}" alt="{{ $proposal->publicProfile->photo_alt ?: 'Project photo for '.$proposal->proponent }}">@endif
                        <p>{{ $proposal->publicProfile->summary }}</p>
                    </details>
                    @endif
                    <button type="button" class="directory-report-button" data-report-key="{{ $mapKeys[$loop->index] }}" data-report-name="{{ $proposal->proponent }}">Report incorrect information</button>

                    @if(in_array($mapKeys[$loop->index], $mappedKeys, true))
                        <button type="button" class="directory-show-map" disabled aria-label="Show {{ $proposal->proponent }} on map">Show on map <span aria-hidden="true">↗</span></button>
                    @else
                        <small class="directory-note">Location not available</small>
                    @endif
                </article>
            @empty
                <div class="directory-empty"><h3>No funded projects found</h3><p>Try a different name, province, or sector.</p><a href="{{ route('public.proposals') }}">Clear filters</a></div>
            @endforelse
        </div>
        <div id="directoryPagination">{{ $proposals->links() }}</div>
    </section>
    <section class="directory-map-section" aria-labelledby="mapHeading">
        <div class="directory-section-heading"><div><span class="eyebrow">EXPLORE THE REGION</span><h2 id="mapHeading" tabindex="-1">Project locations</h2></div><button type="button" class="directory-map-reset" id="directoryMapReset" disabled>Show all locations</button></div>
        <p class="directory-selection" id="directorySelection" role="status" aria-live="polite" aria-atomic="true">Choose a proponent or map pin to view its details.</p>
        <div id="publicProjectMap" aria-label="Map showing funded project locations" aria-describedby="mapHelp"></div>
        <p id="mapHelp" class="directory-note">Select a numbered group to zoom in. Province colors match the labels below; gray groups span multiple provinces.</p>
        @if(count($locations) === 0)<p class="directory-map-empty">No saved locations for these results. You can still browse the directory.</p>@endif
        <details class="directory-map-info"><summary>About these locations</summary><p>{{ count($locations) }} of {{ $proposals->total() }} matching projects have saved locations; projects without coordinates remain in the list. Only proponent names, addresses, sectors, provinces, and saved locations are shared publicly.</p></details>
        <p id="mapUnavailable" hidden>The map could not load. You can still browse every result in the project list.</p>
        <noscript><p>Enable JavaScript to view the map. The project list and search work without it.</p></noscript>
        <details class="directory-legend" open>
            <summary><span>Map legend</span><span class="directory-legend-toggle" aria-hidden="true"></span></summary>
            <div class="directory-legend-body">
            <section aria-labelledby="legendProvinces">
            <h3 id="legendProvinces">Provinces</h3>
            <p>Color identifies the province. Select one to filter.</p>
        <div class="directory-provinces" aria-label="Province results">
            @foreach($regions as $region)
                <a data-province-color="{{ $region['province'] }}" href="{{ route('public.proposals', array_filter(['date_from' => $filters['date_from'] ?? null, 'date_to' => $filters['date_to'] ?? null, 'search' => $filters['search'] ?? null, 'sector' => $filters['sector'] ?? null, 'industry' => $filters['industry'] ?? null, 'province' => $region['province']])) }}">{{ $region['province'] ?: 'Province unspecified' }} <b>{{ $region['total'] }}</b></a>
            @endforeach
        </div>
            <p class="directory-legend-count-note">Counts include all matching funded projects, including those without a saved location.</p>
            </section>
            <section aria-labelledby="legendSymbols">
                <h3 id="legendSymbols">Map symbols</h3>
                <ul class="directory-legend-symbols">
                    <li><span class="directory-industry-pin" style="--province-color:#196fc0" aria-hidden="true"><i class="fa-solid {{ \App\Support\IndustrySectorIcon::forName('Food processing') }}"></i></span><span><strong>Project location</strong><small>Select a pin for proponent details. A location may have several projects.</small></span></li>
                    <li><span class="directory-legend-cluster" aria-hidden="true">5</span><span><strong>Grouped projects</strong><small>The number is the project count. Select a group or zoom in to explore.</small></span></li>
                    <li><span class="directory-legend-cluster directory-legend-mixed" aria-hidden="true">8</span><span><strong>Multiple provinces</strong><small>Gray pins or groups contain projects from different provinces.</small></span></li>
                </ul>
            </section>
            <section class="directory-legend-industries" aria-labelledby="legendIndustries">
                <h3 id="legendIndustries">Industry sectors</h3>
                <p>Icon shapes and colors identify the industry; pin borders identify the province. Counts reflect the current filters.</p>
                <ul>
                    @foreach($industries as $industry)
                        <li><span class="directory-industry-key" aria-hidden="true"><i class="fa-solid {{ \App\Support\IndustrySectorIcon::forName($industry['name']) }}"></i></span><a href="{{ route('public.proposals', array_filter(['date_from' => $filters['date_from'] ?? null, 'date_to' => $filters['date_to'] ?? null, 'search' => $filters['search'] ?? null, 'province' => $filters['province'] ?? null, 'sector' => $filters['sector'] ?? null, 'industry' => $industry['name'] === 'Not classified' ? '__unclassified__' : $industry['name']])) }}" @if(($filters['industry'] ?? '') === ($industry['name'] === 'Not classified' ? '__unclassified__' : $industry['name'])) aria-current="true" @endif>{{ $industry['name'] }}</a><b>{{ $industry['total'] }}</b></li>
                    @endforeach
                </ul>
                <p class="directory-legend-count-note">A layered pin <i class="fa-solid fa-layer-group" aria-hidden="true"></i> represents multiple industries at the same location. Select it to see each project.</p>
            </section>
            </div>
        </details>
    </section>
    </div>
</main>
<dialog id="directoryReportDialog" class="public-login-dialog" aria-labelledby="reportTitle">
    <form id="directoryReportForm" class="public-login-card">
        <button type="button" class="public-login-close" id="directoryReportClose" aria-label="Close report form">&times;</button>
        <h2 id="reportTitle">Report incorrect information</h2>
        <p id="directoryReportName"></p>
        <p>Please describe the correction. Do not include personal or confidential information.</p>
        <label>What needs correcting?<select name="category" required><option value="address">Address</option><option value="location">Map location</option><option value="industry">Industry sector</option><option value="other">Other public details</option></select></label>
        <label>Details<textarea name="message" required minlength="10" maxlength="2000" rows="4"></textarea></label>
        <p id="directoryReportStatus" role="status" aria-live="polite"></p>
        <button class="btn primary" type="submit">Send for review</button>
    </form>
</dialog>
@guest
<dialog id="staffLoginDialog" class="public-login-dialog" aria-labelledby="staffLoginTitle" @if($errors->login->any() || $errors->forgot->any() || session('password_reset_status') || request()->boolean('login')) data-open-on-load @endif @if($errors->forgot->any() || session('password_reset_status')) data-initial-view="forgot" @endif>
    <section class="public-login-card">
        <button class="public-login-close" type="button" aria-label="Close sign in dialog">×</button>
        <header class="public-login-header" data-login-heading>
            <img src="{{ asset('images/path-logo.png') }}" alt="" aria-hidden="true">
            <div><span class="eyebrow">AUTHORIZED STAFF ACCESS</span><h2 id="staffLoginTitle">Sign in to PATH</h2><p>Use your assigned account to continue to the staff portal.</p></div>
        </header>
        @if($errors->login->any())
            <div class="alert danger public-login-alert" role="alert">{{ $errors->login->first() }}</div>
        @endif
        <form method="POST" action="{{ route('login.attempt') }}" class="login-form public-login-form" data-login-panel>
            @csrf
            <label for="publicLoginUsername">Username
                <input id="publicLoginUsername" name="username" value="{{ old('username') }}" required autocomplete="username">
            </label>
            <label for="publicLoginPassword">Password
                <input id="publicLoginPassword" type="password" name="password" required autocomplete="current-password">
            </label>
            <div class="login-form-row">
                <label class="check" for="publicLoginRemember"><input id="publicLoginRemember" type="checkbox" name="remember"><span>Keep me signed in</span></label>
                <button type="button" class="public-login-link" data-show-forgot>Forgot password?</button>
            </div>
            <button class="btn primary wide login-submit" type="submit"><span>Sign in</span><span aria-hidden="true">→</span></button>
        </form>
        <section data-forgot-panel hidden>
            <div class="public-forgot-copy"><span class="eyebrow">ACCOUNT RECOVERY</span><h2>Reset your password</h2><p>Enter the email address registered to your PATH account.</p></div>
            @if(session('password_reset_status'))<div class="alert success public-login-alert" role="status">{{ session('password_reset_status') }}</div>@endif
            @if($errors->forgot->any())<div class="alert danger public-login-alert" role="alert">{{ $errors->forgot->first() }}</div>@endif
            <form method="POST" action="{{ route('password.email') }}" class="login-form public-login-form">@csrf
                <label for="forgotEmail">Email address<input id="forgotEmail" type="email" name="email" value="{{ old('email') }}" required autocomplete="email"></label>
                <button class="btn primary wide" type="submit">Send reset link</button>
                <button class="public-login-link public-login-back" type="button" data-show-login>Back to sign in</button>
            </form>
        </section>
        <nav class="public-login-legal" aria-label="Legal information">
            <a href="{{ route('legal.privacy') }}">Privacy</a><a href="{{ route('legal.terms') }}">Terms</a><a href="{{ route('legal.cookies') }}">Cookies</a><a href="{{ route('legal.accessibility') }}">Accessibility</a>
        </nav>
    </section>
</dialog>
@endguest
<footer class="legal-footer">&copy; {{ now()->year }} DOST Central Visayas. All rights reserved.</footer>
<script>window.publicDirectory = {{ Illuminate\Support\Js::from([
    'locations' => $locations,
    'initialBounds' => $initialBounds,
    'url' => route('public.proposals'),
    'reportUrl' => url('/public/projects'),
    'search' => $filters['search'] ?? '',
    'sector' => $filters['sector'] ?? '',
]) }};</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" defer></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js" defer></script>
<script src="{{ asset('js/public-directory.js') }}?v={{ filemtime(public_path('js/public-directory.js')) }}" defer></script>
@guest
<script src="{{ asset('js/public-login-modal.js') }}?v={{ filemtime(public_path('js/public-login-modal.js')) }}" defer></script>
<script src="{{ asset('js/path-password-toggle.js') }}" defer></script>
@endguest
</body>
</html>

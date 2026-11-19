@extends('layouts.legal')
@section('title', 'Cookie Policy')
@section('heading', 'Cookie Policy')
@section('content')
<div class="legal-callout"><strong>Current consent assessment:</strong> PATH does not presently need an optional-cookie consent banner because it does not install advertising or behavioral-analytics cookies. This assessment must be repeated before adding any tracker, embedded media, chat widget, or public portal analytics.</div>

<section><h2>1. What cookies are used</h2><div class="legal-table-wrap"><table><thead><tr><th>Cookie</th><th>Purpose</th><th>Duration</th><th>Type</th></tr></thead><tbody><tr><td><code>path_session</code></td><td>Maintains the authenticated session, security state, and form messages.</td><td>Normally expires after the configured inactivity period; browser behavior may vary.</td><td>Strictly necessary</td></tr><tr><td>CSRF/session security data</td><td>Helps protect submitted forms from cross-site request forgery.</td><td>Session-related</td><td>Strictly necessary</td></tr><tr><td>Laravel “remember me” token</td><td>Keeps a user signed in only when “Keep me signed in” is selected.</td><td>Persistent until expiry, logout, token rotation, or browser removal.</td><td>User-requested authentication</td></tr></tbody></table></div></section>

<section><h2>2. Analytics</h2><p>The menu item named “Analytics” computes charts from proposal records. It is not a web-traffic or user-behavior tracker. No Google Analytics, Meta Pixel, Hotjar, Microsoft Clarity, or comparable tracker was found in the reviewed code.</p></section>

<section><h2>3. External requests</h2><p>Fonts, icons, chart/map libraries, and OpenStreetMap tiles may currently be requested from external providers. Those requests can expose normal connection metadata even when they do not create a PATH cookie. See the Privacy Policy for details.</p></section>

<section><h2>4. Browser controls</h2><p>Users may remove or block cookies through browser settings, but blocking strictly necessary cookies will prevent login and protected forms from working. Users can avoid the persistent authentication token by leaving “Keep me signed in” unchecked.</p></section>

<section><h2>5. Future changes</h2><p>Optional cookies or trackers must be disabled by default until a valid choice is obtained where consent is the applicable lawful basis. PATH must provide equally accessible Accept, Reject, and Manage choices and make withdrawal as easy as acceptance.</p></section>
@endsection

@extends('layouts.legal')
@section('title', 'Accessibility Statement')
@section('heading', 'Accessibility Statement')
@section('content')
<div class="legal-callout"><strong>Conformance status:</strong> PATH has not yet completed an independent accessibility certification. DOST VII is working toward WCAG 2.2 Level AA while recognizing WCAG 2.0 as the standard adopted by the Philippine Web Accessibility Policy.</div>

<section><h2>Accessibility measures</h2><ul><li>Semantic headings, labels, tables, and landmarks are used throughout the application.</li><li>A keyboard “Skip to main content” link and visible focus indicators are provided.</li><li>Meaningful images include text alternatives, while many decorative icons are hidden from assistive technology.</li><li>Forms provide visible labels, validation messages, and descriptive action names.</li><li>Layouts adapt to smaller screens, and nonessential login animation respects the browser’s reduced-motion preference.</li></ul></section>

<section><h2>Known limitations</h2><p>Some dense data tables require horizontal navigation at high zoom, some charts and custom dialogs still need complete assistive-technology verification, and the interactive map depends on a visual map interface alongside its text address and coordinate fields. These items remain in the remediation register and must be tested before a public launch.</p></section>

<section><h2>Feedback and alternate assistance</h2><p>If a user cannot access a PATH feature or document, contact {{ config('compliance.organization') }} at <a href="mailto:{{ config('compliance.privacy_email') }}">{{ config('compliance.privacy_email') }}</a> or through the official office channels. Include the page, task, browser or assistive technology used, and the difficulty encountered. DOST VII should provide an accessible alternative process while the issue is addressed.</p></section>

<section><h2>Assessment approach</h2><p>Accessibility should be evaluated through automated checks and manual testing using keyboard-only navigation, screen readers, text zoom, browser reflow, contrast review, and representative user testing. This statement must be updated as findings are fixed or new features are introduced.</p></section>
@endsection

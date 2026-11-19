@extends('layouts.legal')
@section('title', 'Terms and Conditions')
@section('heading', 'Terms and Conditions')
@section('content')
<div class="legal-callout"><strong>Authorized-use system.</strong> These terms are an operational draft and do not replace DOST policies, employment obligations, records rules, contracts, or applicable law.</div>

<section><h2>1. Permitted use</h2><p>PATH is provided for authorized proposal administration, evaluation, funding, monitoring, reporting, and related official work. Users may act only within their assigned role, data scope, and lawful authority.</p></section>

<section><h2>2. Account responsibilities</h2><ul><li>Keep credentials confidential and use only your own account.</li><li>Provide accurate information and promptly correct known errors.</li><li>Do not bypass permissions, probe vulnerabilities without written authority, disrupt the service, introduce malicious code, or access records unrelated to official duties.</li><li>Report suspected unauthorized access, data loss, or credential compromise immediately through approved DOST VII channels.</li></ul></section>

<section><h2>3. Confidentiality and personal data</h2><p>Proposal files, personal details, evaluator remarks, financial records, and exports may be confidential or restricted. Access does not authorize copying, publication, personal use, or disclosure. Users must comply with the Privacy Policy, the Data Privacy Act, DOST policies, audit rules, and records-management requirements.</p></section>

<section><h2>4. Uploaded content and intellectual property</h2><p>Users must upload only material they are authorized to process and must not upload unlawful, malicious, defamatory, or infringing material. Third-party software, icons, fonts, maps, and data remain subject to their own licenses. Use of the DOST or PATH identity must follow agency authorization and branding rules.</p></section>

<section><h2>5. Records and monitoring</h2><p>Actions may be logged for security, accountability, troubleshooting, audit, and lawful records management. Records may be preserved despite account closure or a deletion request when retention is required by law or an approved government records schedule.</p></section>

<section><h2>6. Service availability</h2><p>DOST VII may maintain, suspend, correct, or restrict the system to protect data and operations. Users should verify critical decisions and exported reports against authoritative records and established approval procedures.</p></section>

<section><h2>7. External services and links</h2><p>External links and services are provided for specific functionality or reference. Users must follow their applicable terms and should not send personal or confidential information to an external service unless the processing is authorized.</p></section>

<section><h2>8. Violations and governing rules</h2><p>Misuse may result in access suspension, administrative action, referral to the appropriate authority, or other consequences allowed by Philippine law and DOST policy. These terms are governed by applicable laws and regulations of the Republic of the Philippines.</p></section>

<section><h2>9. Contact and changes</h2><p>Questions may be sent to <a href="mailto:{{ config('compliance.privacy_email') }}">{{ config('compliance.privacy_email') }}</a>. Material changes should be reviewed and communicated before taking effect.</p></section>
@endsection

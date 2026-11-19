@extends('layouts.app')
@section('title', $title)
@section('page-title', $title)

@section('content')
<div class="page-intro">
    <div>
        <h1>{{ $title }}</h1>
    </div>
    <div class="summary-pill">
        <span>Module status</span>
        <b>Ready</b>
    </div>
</div>

<div class="metric-grid three">
    <article class="metric">
        <span class="metric-icon blue"><i class="fa-solid {{ $icon }}" aria-hidden="true"></i></span>
        <div>
            <small>Workspace</small>
            <strong>{{ $shortLabel }}</strong>
            <em>Monitoring module prepared</em>
        </div>
    </article>
    <article class="metric">
        <span class="metric-icon"><i class="fa-solid fa-layer-group" aria-hidden="true"></i></span>
        <div>
            <small>Data scope</small>
            <strong>Overall</strong>
            <em>Proposal-wide monitoring structure</em>
        </div>
    </article>
    <article class="metric">
        <span class="metric-icon dark"><i class="fa-solid fa-sliders" aria-hidden="true"></i></span>
        <div>
            <small>Configuration</small>
            <strong>Pending</strong>
            <em>KPIs and datasets to be defined</em>
        </div>
    </article>
</div>

<section class="panel" style="margin-top:1rem;">
    <div class="panel-head">
        <div>
            <span class="eyebrow">WORKSPACE PREPARED</span>
            <h2>{{ $title }}</h2>
        </div>
    </div>
    <div class="empty" style="padding:42px 24px;">
        <i class="fa-solid {{ $icon }}" aria-hidden="true" style="display:block;font-size:28px;margin-bottom:12px;"></i>
        <b style="display:block;margin-bottom:6px;">Monitoring data configuration is pending.</b>
        <span>The module, route, navigation, and role permission are ready. Overall KPIs, tables, charts, filters, and exports can be added once the monitoring requirements are finalized.</span>
    </div>
</section>

<div class="grid two" style="gap:1rem;margin-top:1rem;">
    <section class="panel">
        <div class="panel-head">
            <div><span class="eyebrow">PLANNED AREA</span><h2>Proposal summary</h2></div>
        </div>
        <p style="margin:0;color:var(--muted);">Reserved for overall totals, status indicators, targets, and performance metrics.</p>
    </section>
    <section class="panel">
        <div class="panel-head">
            <div><span class="eyebrow">PLANNED AREA</span><h2>Monitoring detail</h2></div>
        </div>
        <p style="margin:0;color:var(--muted);">Reserved for searchable records, filters, trends, charts, and downloadable monitoring reports.</p>
    </section>
</div>
@endsection

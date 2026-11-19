@extends('layouts.app')
@section('title','Overview')
@section('page-title', auth()->user()->isEvaluator() ? 'Evaluator Overview' : 'Proposal Overview')

@section('top-actions')
    <a class="btn" href="{{ route('monitoring.annual', request()->only('year', 'date_from', 'date_to')) }}"><i class="fa-solid fa-chart-column" aria-hidden="true"></i> Annual Monitoring</a>
    @if(auth()->user()->hasPermission('proposals.create'))
        <a class="btn primary" href="{{ route('proposals.create') }}">+ New Proposal</a>
    @endif
@endsection

@section('content')
@php
    $isEvaluator = auth()->user()->isEvaluator();
    $proposalUrl = fn ($proposal) => $isEvaluator
        ? route('evaluator.proposals.show', $proposal)
        : route('proposals.show', $proposal);
@endphp

<div class="hero-panel">
    <div>
        <span class="eyebrow light">{{ $range->label($year) }} {{ $isEvaluator ? 'ASSIGNED PROPOSALS' : 'PROPOSALS' }}</span>
        <h1>Good {{ now()->hour<12?'morning':(now()->hour<18?'afternoon':'evening') }}, {{ auth()->user()->first_name }}.</h1>
    </div>
    <form class="year-select">
        <label>Reporting year
            <select name="year" >
                @forelse($years as $y)
                    <option value="{{ $y }}" {{ $year == $y ? 'selected' : '' }}>{{ $y }}</option>
                @empty
                    <option value="{{ $year }}">{{ $year }}</option>
                @endforelse
            </select>
        </label>
    
        @include('partials.date-range-filter', ['dateBasis' => 'Submission date', 'overridesYear' => true])
        <button type="submit" class="btn primary">Apply</button>
    </form>
</div>

<div class="metric-grid">
    <article class="metric">
        <span class="metric-icon">✓</span>
        <div><small>Complete documents</small><strong>{{ number_format($stats['complete']) }}</strong><em class="metric-total">&#8369;{{ number_format($stats['complete_amount'], 0) }} total</em></div>
    </article>
    <article class="metric">
        <span class="metric-icon amber">!</span>
        <div><small>Incomplete documents</small><strong>{{ number_format($stats['incomplete']) }}</strong><em class="metric-total">&#8369;{{ number_format($stats['incomplete_amount'], 0) }} total</em></div>
    </article>
    <article class="metric">
        <span class="metric-icon blue">↑</span>
        <div><small>Approved · awaiting funding</small><strong>{{ number_format($stats['approved']) }}</strong><em class="metric-total">&#8369;{{ number_format($stats['approved_amount'], 0) }} awaiting funding</em><span class="metric-subtotal">{{ number_format($stats['approved_cumulative']) }} total approved · &#8369;{{ number_format($stats['approved_cumulative_amount'], 0) }}</span></div>
    </article>
    <article class="metric">
        <span class="metric-icon dark">₱</span>
        <div><small>Funded proposals</small><strong>{{ number_format($stats['funded']) }}</strong><em class="metric-total">&#8369;{{ number_format($stats['funded_amount'], 0) }} total</em></div>
    </article>
</div>

<div class="grid two">
    <section class="panel">
        <div class="panel-head">
            <div><span class="eyebrow">PROGRAM MIX</span><h2>{{ $isEvaluator ? 'My proposals by province and program' : 'Proposals by province and program' }}</h2></div>
            @if(auth()->user()->hasPermission('analytics.view'))<a href="{{ route('analytics.index',array_merge(['year'=>$year], request()->only('date_from', 'date_to'))) }}">View analytics →</a>@endif
        </div>
        @if(collect($programCharts['submitted']['labels'])->isNotEmpty())
            <div class="dashboard-chart-grid">
                <section class="dashboard-chart-card">
                    <h3>Submitted proposals</h3>
                    <div class="dashboard-chart-wrap">
                        <canvas id="dashboardSubmittedChart" aria-label="Submitted proposals by province and program sector" role="img"></canvas>
                    </div>
                </section>
                <section class="dashboard-chart-card">
                    <h3>Approved proposals · cumulative</h3>
                    <div class="dashboard-chart-wrap">
                        <canvas id="dashboardApprovedChart" aria-label="Approved proposals by province and program sector" role="img"></canvas>
                    </div>
                </section>
            </div>
        @else
            <p class="empty">No proposal data is available for this reporting period.</p>
        @endif
    </section>

    <section class="panel">
        <div class="panel-head"><div><span class="eyebrow">ACTION QUEUE</span><h2>Recent lacking documents</h2></div></div>
        <div class="activity-list dashboard-scroll-list dashboard-lacking-list">
            @forelse($missing as $m)
                <a href="{{ $proposalUrl($m->proposal) }}">
                    <span class="dot amber"></span>
                    <div><b title="{{ $m->proposal->proponent }}">{{ $m->proposal->proponent }}</b><small title="{{ $m->description }}">{{ $m->description }}</small></div>
                    <time datetime="{{ $m->created_at->toIso8601String() }}">{{ $m->created_at->diffForHumans() }}</time>
                    <span>→</span>
                </a>
            @empty
                <p class="empty">No outstanding entries.</p>
            @endforelse
        </div>
    </section>
</div>

<div class="dashboard-wide-row">
    <section class="panel">
        <div class="panel-head">
            <div>
                <span class="eyebrow">{{ $isEvaluator ? 'MY AUDIT TRAIL' : 'AUDIT TRAIL' }}</span>
                <h2>{{ $isEvaluator ? 'My latest evaluation activity' : 'Latest activity' }}</h2>
            </div>
        </div>
        <div class="activity-list dashboard-scroll-list">
            @forelse($activity as $a)
                <a href="{{ $proposalUrl($a->proposal) }}">
                    <span class="dot"></span>
                    <div>
                        <b>{{ $a->action }}</b>
                        <small>{{ $a->proposal->project_title }}{{ $isEvaluator ? '' : ' · '.($a->user?->full_name ?? 'System') }}</small>
                    </div>
                    <time>{{ $a->created_at->diffForHumans(null,true) }}</time>
                </a>
            @empty
                <p class="empty">{{ $isEvaluator ? 'You have no evaluation activity yet.' : 'No activity yet.' }}</p>
            @endforelse
        </div>
    </section>
</div>
@endsection

@push('scripts')
@if(collect($programCharts['submitted']['labels'])->isNotEmpty())
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    if (typeof Chart === 'undefined') return;

    const chartCollection = @json($programCharts);
    const palette = {
        SETUP: { background: 'rgba(37, 116, 169, .78)', border: '#2574a9' },
        GIA: { background: 'rgba(230, 126, 34, .78)', border: '#e67e22' },
        CEST: { background: 'rgba(38, 166, 91, .78)', border: '#26a65b' },
        SMART: { background: 'rgba(139, 92, 196, .78)', border: '#8b5cc4' },
    };

    [
        { id: 'dashboardSubmittedChart', data: chartCollection.submitted },
        { id: 'dashboardApprovedChart', data: chartCollection.approved },
    ].forEach(definition => {
        const canvas = document.getElementById(definition.id);
        if (!canvas) return;

        definition.data.datasets = definition.data.datasets.map(dataset => ({
            ...dataset,
            backgroundColor: palette[dataset.label].background,
            borderColor: palette[dataset.label].border,
            borderWidth: 1,
            borderRadius: 4,
            borderSkipped: false,
            maxBarThickness: 24,
        }));

        new Chart(canvas, {
            type: 'bar',
            data: definition.data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { usePointStyle: true, pointStyle: 'rectRounded', boxWidth: 8, boxHeight: 8, padding: 12, color: '#496473', font: { size: 9, weight: '600' } },
                    },
                    tooltip: {
                        backgroundColor: '#0b273d',
                        padding: 11,
                        cornerRadius: 8,
                        callbacks: { label: context => ` ${context.dataset.label}: ${context.parsed.y} proposal${context.parsed.y === 1 ? '' : 's'}` },
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: { color: '#607986', font: { size: 8, weight: '600' }, maxRotation: 40, minRotation: 0 },
                    },
                    y: {
                        beginAtZero: true,
                        grace: '10%',
                        border: { display: false },
                        grid: { color: '#e9eef1' },
                        ticks: { precision: 0, stepSize: 1, color: '#718793', font: { size: 8 } },
                    },
                },
            },
        });
    });
});
</script>
@endif
@endpush

@extends('layouts.app')
@section('title', 'Annual Monitoring')
@section('page-title', 'Annual Monitoring')
@section('top-actions')<a class="btn" href="{{ route('monitoring.annual.export', request()->only(['year', 'date_from', 'date_to', 'region', 'province', 'monitoring_status'])) }}"><i class="fa-solid fa-file-excel" aria-hidden="true"></i> Export Excel</a>@endsection
@section('top-back')<a class="btn" href="{{ route('dashboard') }}">Back to Overview</a>@endsection
@section('content')
@php
    $money = fn ($value) => '₱'.number_format($value, 2);
    $colors = ['#148c87', '#397cc5', '#d98a26', '#9b69b8', '#748797', '#d2606e'];
    $statusColors = ['New' => '#20b9aa', 'Ongoing' => '#ffad42', 'Stopped Payment' => '#f2637a', 'Completed' => '#408ef4', 'Graduated' => '#a477ed', 'Terminated' => '#e84343', 'Withdrawn' => '#e65ac4'];
    $sexColors = ['Male' => '#397cc5', 'Female' => '#b15399', 'Prefer not to say' => '#d98a26', 'Not recorded' => '#748797'];
    $maximum = max(1, $provinceRows->max('fund') ?? 0);
    $statusTotal = $statuses->sum();
    $stops = []; $angle = 0;
    foreach ($statuses as $status => $count) {
        $end = $angle + $count / max(1, $statusTotal) * 360;
        $stops[] = ($statusColors[$status] ?? '#8496a8').' '.$angle.'deg '.$end.'deg';
        $angle = $end;
    }
@endphp
<div class="annual-dashboard">
<header class="annual-heading">
    <div><span class="eyebrow">SETUP • {{ $cutoff->format('Y') }}</span><h1>Annual Monitoring</h1><small>As of {{ $cutoff->format('M d, Y') }}</small></div>
    <form class="annual-period" method="GET">
        <input type="hidden" name="monitoring_status" value="{{ $selectedStatus }}"><input type="hidden" name="region" value="{{ $region }}"><input type="hidden" name="province" value="{{ $province }}">
        <label>Reporting year<select name="year">@foreach($years as $option)<option value="{{ $option }}" @selected($year === $option)>{{ $option }}</option>@endforeach</select></label>
        @include('partials.date-range-filter', ['dateBasis' => 'Reporting date'])
        <button class="btn primary">Apply</button>
    </form>
</header>
<div class="annual-kpis">
    @foreach(['fund' => 'Funds released', 'paid' => 'Funds refunded', 'count' => 'Projects funded', 'balance' => 'Funds to be settled'] as $key => $label)
    <section class="annual-card annual-kpi {{ $key }}"><h2>{{ $label }}</h2>
        @foreach($summaries as $name => $summary)<div><small>{{ $name }}</small><strong>{{ $key === 'count' ? number_format($summary[$key]) : $money($summary[$key]) }}</strong></div>@endforeach
    </section>
    @endforeach


    <section class="annual-card annual-kpi annual-status-summary">
        <h2>Percent of Projects Funded per Status</h2>
        <div class="annual-status-shares">
            @foreach($statusShares as $name => $share)<div><small>{{ $name }}</small><strong>{{ $share === null ? '?' : number_format($share, 1).'%' }}</strong></div>@endforeach
        </div>
        <form method="GET">
            <input type="hidden" name="year" value="{{ $year }}">
            <input type="hidden" name="region" value="{{ $region }}">
            <input type="hidden" name="province" value="{{ $province }}">
            @foreach(['date_from', 'date_to'] as $key)<input type="hidden" name="{{ $key }}" value="{{ request($key) }}">@endforeach
            <label class="sr-only" for="monitoring-status">Monitoring status</label>
            <select id="monitoring-status" name="monitoring_status" onchange="this.form.submit()">@foreach($statusOptions as $option)<option @selected($selectedStatus === $option)>{{ $option }}</option>@endforeach</select>
            <noscript><button>Apply status</button></noscript>
        </form>

    </section>

</div>
<div class="annual-grid">
    <div class="annual-finance">
        <section class="annual-card annual-rate"><h2>Accumulated refund rate</h2><table><thead><tr><th>Coverage</th><th title="All funding years through {{ $targetDate->format('M d, Y') }}">Target average</th><th>Actual average</th></tr></thead><tbody>
            @foreach($summaries as $name => $summary)<tr><th>{{ $name }}</th><td><strong>{{ $targetAverages[$name] === null ? '?' : number_format($targetAverages[$name], 2).'%' }}</strong></td><td><strong>{{ $summary['average'] === null ? '—' : number_format($summary['average'], 2).'%' }}</strong></td></tr>@endforeach
        </tbody></table></section>
        <div class="annual-finance-charts">
            <section class="annual-card"><h2>Funds released by province</h2><div class="annual-chart-body">
                @forelse($provinceRows as $name => $row)<div class="annual-bar-row"><div><b>{{ $name }}</b><span>{{ $money($row['fund']) }}</span></div><div class="annual-track"><i style="width:{{ $row['fund'] / $maximum * 100 }}%;background:{{ $colors[$loop->index % count($colors)] }}"></i></div></div>@empty<p>No funded SETUP projects in this period.</p>@endforelse
            </div></section>
            <section class="annual-card"><h2>Cumulative refund progress</h2><div class="annual-legend"><span><i style="background:#148c87"></i>Refunded</span><span><i style="background:#efbd78"></i>To be settled</span></div><div class="annual-chart-body">
                @forelse($provinceRows as $name => $row)<div class="annual-bar-row"><div><b>{{ $name }}</b><span>{{ number_format($row['paid'] / max(1, $row['fund']) * 100, 1) }}% refunded</span></div><div class="annual-track stacked" aria-label="{{ $name }}: {{ $money($row['paid']) }} refunded; {{ $money($row['balance']) }} to be settled"><i style="width:{{ $row['paid'] / max(1, $row['paid'] + $row['balance']) * 100 }}%;background:#148c87"></i><i style="width:{{ $row['balance'] / max(1, $row['paid'] + $row['balance']) * 100 }}%;background:#efbd78"></i></div><div class="annual-values"><span>{{ $money($row['paid']) }}</span><span>{{ $money($row['balance']) }}</span></div></div>@empty<p>No refund data for this period.</p>@endforelse
            </div></section>
        </div>
    </div>
    <div class="annual-visuals">
        <form class="annual-visual-filters" method="GET"><input type="hidden" name="monitoring_status" value="{{ $selectedStatus }}">
            <input type="hidden" name="year" value="{{ $year }}"><input type="hidden" name="date_from" value="{{ request('date_from') }}"><input type="hidden" name="date_to" value="{{ request('date_to') }}">
            <label>Visuals · Region<select name="region"><option value="all" @selected($region === 'all')>All regions</option><option value="cv" @selected($region === 'cv')>Central Visayas</option><option value="nir" @selected($region === 'nir')>NIR</option></select></label>
            <label>Province<select name="province"><option value="">All provinces</option>@foreach($provinces as $name)<option @selected($province === $name)>{{ $name }}</option>@endforeach</select></label><button class="btn primary">Apply</button>
        </form>
        <div class="annual-visual-pair">
            <section class="annual-card"><h2>Sex disaggregated data</h2><div class="annual-sex-cards">
                @foreach($sexRows as $sex => $row)@if(in_array($sex, ['Male', 'Female']))<div style="--sex-color:{{ $sexColors[$sex] }}"><span class="annual-person" aria-hidden="true">{{ $sex === 'Male' ? '♂' : '♀' }}</span><b>{{ $sex }}</b><div class="annual-sex-funds"><small>Funds released</small><strong>{{ $money($row['fund']) }}</strong></div><div class="annual-sex-count"><small>No. of projects funded</small><strong>{{ number_format($row['count']) }}</strong></div></div>@endif @endforeach
            </div><div class="annual-unrecorded">@foreach($sexRows as $sex => $row)@if(!in_array($sex, ['Male', 'Female']))<div><span>{{ $sex }}</span><b>{{ $row['count'] }}</b><span>{{ $money($row['fund']) }}</span></div>@endif @endforeach</div></section>
            <section class="annual-card"><h2>Percent of Projects Funded per Status</h2><div class="annual-status-body"><div class="annual-pie" style="background:{{ $statusTotal ? 'conic-gradient('.implode(', ', $stops).')' : '#e5ecf1' }}" role="img" aria-label="{{ $statusTotal }} projects; status breakdown below"></div><div class="annual-status-legend"><p class="annual-status-total"><b>{{ $statusTotal }}</b> projects</p>
                @forelse($statuses as $status => $count)<div><i style="background:{{ $statusColors[$status] ?? '#8496a8' }}"></i><span>{{ $status }}</span><b>{{ number_format($count / max(1, $statusTotal) * 100, 1) }}%</b><small>({{ $count }})</small></div>@empty<p>No matching projects.</p>@endforelse
            </div></div></section>
        </div>
        <section class="annual-card annual-no-refunds"><h2>Projects with no refunds since rental start</h2><div class="annual-legend">@foreach($sexColors as $sex => $color)<span><i style="background:{{ $color }}"></i>{{ $sex }}</span>@endforeach</div><div class="annual-no-refund-chart">
            @php($noRefundMax = max(1, $noRefundRows->map(fn ($r) => $r->sum())->max() ?? 0))
            @forelse($noRefundRows as $name => $counts)<div class="annual-no-refund-row"><b>{{ $name }}</b><div class="annual-track stacked">@foreach($counts as $sex => $count)@if($count)<i style="width:{{ $count / $noRefundMax * 100 }}%;background:{{ $sexColors[$sex] }}" title="{{ $sex }}: {{ $count }}">{{ $count }}</i>@endif @endforeach</div><strong>{{ $counts->sum() }}</strong><span class="sr-only">@foreach($counts as $sex => $count){{ $sex }}: {{ $count }}. @endforeach</span></div>@empty<p>No projects match these visual filters.</p>@endforelse
        </div></section>
    </div>
</div>
<details class="annual-definitions"><summary>Data and calculation details</summary><p>This is an accumulated report of SETUP projects funded on or before the reporting date, including earlier funding years. The current year reports through today; a past year reports through December 31. A custom reporting range overrides the year. Accumulated figures include earlier balances through Date To (or today when only Date From is selected). Funds released use proposal amounts. Refunds use saved rental payments through the cutoff, dated by OR date or due month. Actual average is the arithmetic mean of each project's refunded percentage; penalties are excluded. Target average uses all funded SETUP projects whose saved rental schedules have started, across all funding years through {{ $targetDate->format('M d, Y') }}. Each project's accumulated scheduled dues through that date are divided by its total fund, then the project percentages are averaged equally. Actual payments do not affect the target. Projects without a started schedule are excluded; a dash means there are no eligible projects. Target and actual figures share the reporting cutoff. Status and sex classifications use the currently saved project information.</p><p>Funds to be settled subtract refunds and dated unexpended-balance deductions. Returned-equipment values are not recorded and cannot yet be deducted. Status visuals use Funded Project Status for projects with a recorded Date Funded. Sex refers to the project owner or contact person. Central Visayas covers Cebu and Bohol; NIR covers Negros/Negros Oriental and Siquijor.</p></details>
</div>
@endsection
@push('styles')
<link rel="stylesheet" href="{{ asset('css/annual-monitoring.css') }}?v=6">
@endpush

@extends('layouts.app')
@section('title', 'RTEC Monitoring')
@section('page-title', 'RTEC Monitoring')

@section('top-actions')
    @include('monitoring.partials.export-buttons', ['routeName' => 'monitoring.rtec.export'])
@endsection

@section('content')
@php
    $money = fn ($amount) => '₱'.number_format((float) $amount, 2);
    $quarterTotal = fn ($rows, $quarter) => $rows->sum($quarter);
    $grandTotal = fn ($rows) => $rows->sum('total');
@endphp

<div class="rtec-page">
    <div class="page-intro rtec-intro">
        <div>
            <h1>RTEC Monitoring</h1>
        </div>
        <form method="GET" action="{{ route('monitoring.rtec') }}" class="rtec-year-filter">
            <label class="reporting-year-field" for="rtec-year"><span>Reporting year</span>
            <select id="rtec-year" name="year" >
                @foreach($years as $yearOption)
                    <option value="{{ $yearOption }}" @selected($year === $yearOption)>{{ $yearOption }}</option>
                @endforeach
            </select></label>
        
        @include('partials.date-range-filter', ['dateBasis' => 'Submission / approval / evaluation date', 'overridesYear' => true])
        <button type="submit" class="btn primary">Apply</button>
    </form>
    </div>

    <div class="rtec-kpi-grid">
        <article class="rtec-kpi">
            <span>For RTEC evaluation</span>
            <strong>{{ number_format($summary['rtec_proposals']) }}</strong>
            <small>Distinct proposals assigned to at least one evaluator in {{ $range->label($year) }}</small>
        </article>
        <article class="rtec-kpi">
            <span>Evaluations completed</span>
            <strong>{{ number_format($summary['completed_evaluations']) }}</strong>
            <small>First evaluator approval/completion recorded in {{ $range->label($year) }}</small>
        </article>
        <article class="rtec-kpi">
            <span>External evaluations</span>
            <strong>{{ number_format($summary['external_completed']) }}</strong>
            <small>Completed external-evaluator reviews</small>
        </article>
        <article class="rtec-kpi rtec-kpi-money">
            <span>External honorarium</span>
            <strong>{{ $money($summary['honorarium_total']) }}</strong>
            <small>₱1,500 × completed external evaluations</small>
        </article>
        <article class="rtec-kpi">
            <span>Proposed SETUP fund</span>
            <strong>{{ $money($summary['proposed_setup_fund']) }}</strong>
            <small>SETUP proposals submitted in {{ $range->label($year) }}</small>
        </article>
        <article class="rtec-kpi rtec-kpi-money">
            <span>Approved SETUP fund</span>
            <strong>{{ $money($summary['approved_setup_fund']) }}</strong>
            <small>Approved / funded SETUP proposals by approval date</small>
        </article>
    </div>

    <section class="panel rtec-section">
        <div class="panel-head rtec-section-head">
            <div>
                <span class="eyebrow">EVALUATOR WORKLOAD</span>
                <h2>Summary of proposals assigned per evaluator</h2>
                <p>Quarter columns use the date the proposal was assigned. “Evaluated” uses the evaluator's first Approved/completion date.</p>
            </div>
            <div class="rtec-rate-pill">External honorarium <b>₱1,500 / evaluated proposal</b></div>
        </div>

        <div class="rtec-evaluator-block">
            <div class="rtec-subhead">
                <div><span class="rtec-class-dot external"></span><b>External evaluators</b></div>
                <small>{{ $externalEvaluators->count() }} evaluator{{ $externalEvaluators->count() === 1 ? '' : 's' }}</small>
            </div>
            <div class="rtec-table-wrap">
                <table class="rtec-table evaluator-table">
                    <thead>
                        <tr>
                            <th>Evaluator</th>
                            <th class="q1">Q1</th>
                            <th class="q2">Q2</th>
                            <th class="q3">Q3</th>
                            <th class="q4">Q4</th>
                            <th>Total assigned</th>
                            <th>Evaluated</th>
                            <th>Honorarium</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($externalEvaluators as $row)
                            <tr>
                                <td><b>{{ $row['name'] }}</b><small>External evaluator{{ $row['archived'] ? ' · Archived' : '' }}</small></td>
                                <td>{{ $row['q1'] }}</td>
                                <td>{{ $row['q2'] }}</td>
                                <td>{{ $row['q3'] }}</td>
                                <td>{{ $row['q4'] }}</td>
                                <td><b>{{ $row['assigned'] }}</b></td>
                                <td><b>{{ $row['completed'] }}</b></td>
                                <td class="money"><b>{{ $money($row['honorarium']) }}</b></td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="rtec-empty-cell">No external evaluator activity recorded for {{ $range->label($year) }}.</td></tr>
                        @endforelse
                    </tbody>
                    @if($externalEvaluators->isNotEmpty())
                        <tfoot>
                            <tr>
                                <th>Total</th>
                                <th>{{ $externalEvaluators->sum('q1') }}</th>
                                <th>{{ $externalEvaluators->sum('q2') }}</th>
                                <th>{{ $externalEvaluators->sum('q3') }}</th>
                                <th>{{ $externalEvaluators->sum('q4') }}</th>
                                <th>{{ $externalEvaluators->sum('assigned') }}</th>
                                <th>{{ $externalEvaluators->sum('completed') }}</th>
                                <th class="money">{{ $money($externalEvaluators->sum('honorarium')) }}</th>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>

        <div class="rtec-evaluator-block internal-block">
            <div class="rtec-subhead">
                <div><span class="rtec-class-dot internal"></span><b>Internal evaluators</b></div>
                <small>No honorarium</small>
            </div>
            <div class="rtec-table-wrap">
                <table class="rtec-table evaluator-table">
                    <thead>
                        <tr>
                            <th>Evaluator</th>
                            <th class="q1">Q1</th>
                            <th class="q2">Q2</th>
                            <th class="q3">Q3</th>
                            <th class="q4">Q4</th>
                            <th>Total assigned</th>
                            <th>Evaluated</th>
                            <th>Honorarium</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($internalEvaluators as $row)
                            <tr>
                                <td><b>{{ $row['name'] }}</b><small>Internal evaluator{{ $row['archived'] ? ' · Archived' : '' }}</small></td>
                                <td>{{ $row['q1'] }}</td>
                                <td>{{ $row['q2'] }}</td>
                                <td>{{ $row['q3'] }}</td>
                                <td>{{ $row['q4'] }}</td>
                                <td><b>{{ $row['assigned'] }}</b></td>
                                <td><b>{{ $row['completed'] }}</b></td>
                                <td class="muted">—</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="rtec-empty-cell">No internal evaluator activity recorded for {{ $range->label($year) }}.</td></tr>
                        @endforelse
                    </tbody>
                    @if($internalEvaluators->isNotEmpty())
                        <tfoot>
                            <tr>
                                <th>Total</th>
                                <th>{{ $internalEvaluators->sum('q1') }}</th>
                                <th>{{ $internalEvaluators->sum('q2') }}</th>
                                <th>{{ $internalEvaluators->sum('q3') }}</th>
                                <th>{{ $internalEvaluators->sum('q4') }}</th>
                                <th>{{ $internalEvaluators->sum('assigned') }}</th>
                                <th>{{ $internalEvaluators->sum('completed') }}</th>
                                <th>—</th>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </section>

    <div class="rtec-grid-two">
        <section class="panel rtec-section">
            <div class="panel-head rtec-section-head compact">
                <div>
                    <span class="eyebrow">RTEC PIPELINE</span>
                    <h2>Proposals for RTEC evaluation by province</h2>
                </div>
            </div>
            <div class="rtec-table-wrap">
                <table class="rtec-table province-table">
                    <thead>
                        <tr><th>Province</th><th class="q1">Q1</th><th class="q2">Q2</th><th class="q3">Q3</th><th class="q4">Q4</th><th>Total</th></tr>
                    </thead>
                    <tbody>
                        @foreach($rtecByProvince as $row)
                            <tr>
                                <td><b>{{ $row['province'] }}</b></td>
                                <td>{{ $row['q1'] }}</td><td>{{ $row['q2'] }}</td><td>{{ $row['q3'] }}</td><td>{{ $row['q4'] }}</td><td><b>{{ $row['total'] }}</b></td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr><th>Total</th><th>{{ $quarterTotal($rtecByProvince, 'q1') }}</th><th>{{ $quarterTotal($rtecByProvince, 'q2') }}</th><th>{{ $quarterTotal($rtecByProvince, 'q3') }}</th><th>{{ $quarterTotal($rtecByProvince, 'q4') }}</th><th>{{ $grandTotal($rtecByProvince) }}</th></tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <section class="panel rtec-section rtec-method-card">
            <div class="panel-head rtec-section-head compact">
                <div><span class="eyebrow">REPORTING BASIS</span><h2>How PATH computes this report</h2></div>
            </div>
            <div class="rtec-method-list">
                <div><span>1</span><p><b>Assigned per evaluator</b> uses the evaluator assignment timestamp and places the assignment in Q1–Q4.</p></div>
                <div><span>2</span><p><b>Evaluated</b> is recorded the first time an evaluator's status reaches Approved, either from the Evaluator Portal or an authorized manual status update.</p></div>
                <div><span>3</span><p><b>External honorarium</b> is ₱1,500 for each completed/evaluated proposal. Internal evaluators receive no honorarium.</p></div>
                <div><span>4</span><p><b>RTEC proposal counts</b> include proposals with at least one evaluator assignment and use the proposal submission quarter.</p></div>
                <div><span>5</span><p><b>SETUP fund tables</b> use submission quarter for proposed funds and approval quarter for approved funds.</p></div>
            </div>
        </section>
    </div>

    <section class="panel rtec-section">
        <div class="panel-head rtec-section-head compact">
            <div>
                <span class="eyebrow">STATUS MONITORING</span>
                <h2>Status of RTEC proposals by province</h2>
                <p>Funded proposals are included under Approved because they have already passed proposal approval.</p>
            </div>
        </div>
        <div class="rtec-status-grid">
            @foreach($statusTables as $statusLabel => $rows)
                <article class="rtec-status-card status-{{ strtolower($statusLabel) }}">
                    <div class="rtec-status-title"><span></span><b>{{ $statusLabel }}</b><em>{{ $rows->sum('total') }}</em></div>
                    <div class="rtec-table-wrap">
                        <table class="rtec-table mini-table">
                            <thead><tr><th>Province</th><th class="q1">Q1</th><th class="q2">Q2</th><th class="q3">Q3</th><th class="q4">Q4</th><th>Total</th></tr></thead>
                            <tbody>
                                @foreach($rows as $row)
                                    <tr><td>{{ $row['province'] }}</td><td>{{ $row['q1'] }}</td><td>{{ $row['q2'] }}</td><td>{{ $row['q3'] }}</td><td>{{ $row['q4'] }}</td><td><b>{{ $row['total'] }}</b></td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    <section class="panel rtec-section">
        <div class="panel-head rtec-section-head compact">
            <div>
                <span class="eyebrow">SETUP FUND MONITORING</span>
                <h2>Proposed and approved SETUP fund by province</h2>
            </div>
        </div>
        <div class="rtec-fund-grid">
            <article class="rtec-fund-card">
                <div class="rtec-fund-card-head">
                    <div><small>PROPOSED</small><h3>Proposed SETUP fund</h3></div>
                    <strong>{{ $money($proposedSetupFund->sum('total')) }}</strong>
                </div>
                <div class="rtec-table-wrap">
                    <table class="rtec-table fund-table">
                        <thead><tr><th>Province</th><th class="q1">Q1</th><th class="q2">Q2</th><th class="q3">Q3</th><th class="q4">Q4</th><th>Total</th></tr></thead>
                        <tbody>
                            @foreach($proposedSetupFund as $row)
                                <tr><td><b>{{ $row['province'] }}</b></td><td>{{ $money($row['q1']) }}</td><td>{{ $money($row['q2']) }}</td><td>{{ $money($row['q3']) }}</td><td>{{ $money($row['q4']) }}</td><td><b>{{ $money($row['total']) }}</b></td></tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr><th>Total</th><th>{{ $money($proposedSetupFund->sum('q1')) }}</th><th>{{ $money($proposedSetupFund->sum('q2')) }}</th><th>{{ $money($proposedSetupFund->sum('q3')) }}</th><th>{{ $money($proposedSetupFund->sum('q4')) }}</th><th>{{ $money($proposedSetupFund->sum('total')) }}</th></tr>
                        </tfoot>
                    </table>
                </div>
            </article>

            <article class="rtec-fund-card approved">
                <div class="rtec-fund-card-head">
                    <div><small>APPROVED</small><h3>Approved SETUP fund</h3></div>
                    <strong>{{ $money($approvedSetupFund->sum('total')) }}</strong>
                </div>
                <div class="rtec-table-wrap">
                    <table class="rtec-table fund-table">
                        <thead><tr><th>Province</th><th class="q1">Q1</th><th class="q2">Q2</th><th class="q3">Q3</th><th class="q4">Q4</th><th>Total</th></tr></thead>
                        <tbody>
                            @foreach($approvedSetupFund as $row)
                                <tr><td><b>{{ $row['province'] }}</b></td><td>{{ $money($row['q1']) }}</td><td>{{ $money($row['q2']) }}</td><td>{{ $money($row['q3']) }}</td><td>{{ $money($row['q4']) }}</td><td><b>{{ $money($row['total']) }}</b></td></tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr><th>Total</th><th>{{ $money($approvedSetupFund->sum('q1')) }}</th><th>{{ $money($approvedSetupFund->sum('q2')) }}</th><th>{{ $money($approvedSetupFund->sum('q3')) }}</th><th>{{ $money($approvedSetupFund->sum('q4')) }}</th><th>{{ $money($approvedSetupFund->sum('total')) }}</th></tr>
                        </tfoot>
                    </table>
                </div>
            </article>
        </div>
    </section>
</div>
@endsection

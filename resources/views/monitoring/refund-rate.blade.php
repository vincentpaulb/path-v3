@extends('layouts.app')
@section('title', 'Refund Rate Monitoring')
@section('page-title', 'Refund Rate Monitoring')

@section('top-actions')
    @include('monitoring.partials.export-buttons', ['routeName' => 'monitoring.refund-rate.export'])
@endsection

@section('content')
@php
    $money = fn ($amount) => '₱'.number_format((float) $amount, 2);
    $percent = function ($rate) {
        $rate = (float) $rate;
        return abs($rate - round($rate)) < 0.005
            ? number_format($rate, 0).'%'
            : number_format($rate, 2).'%';
    };
@endphp

<div class="refund-monitor-page">
    <div class="page-intro refund-monitor-intro">
        <h1>Refund Rate Monitoring</h1>
        <form method="GET" action="{{ route('monitoring.refund-rate') }}" class="refund-monitor-filters">
            <label>
                <span>Reporting year</span>
                <select name="year">
                    @foreach($years as $yearOption)
                        <option value="{{ $yearOption }}" @selected((int) $yearOption === (int) $year)>{{ $yearOption }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>Sector</span>
                <select name="sector">
                    <option value="">All sectors</option>
                    @foreach($sectorOptions as $sectorOption)
                        <option value="{{ $sectorOption }}" @selected($sector === $sectorOption)>{{ $sectorOption }}</option>
                    @endforeach
                </select>
            </label>
            
        @include('partials.date-range-filter', ['dateBasis' => 'Due date / receipt date', 'overridesYear' => true])
        <button class="btn primary" type="submit"><i class="fa-solid fa-filter" aria-hidden="true"></i> Apply</button>
            <a class="btn" href="{{ route('monitoring.refund-rate') }}">Reset</a>
        </form>
    </div>

    <section class="panel refund-monitor-sheet">
        <div class="refund-monitor-table-wrap">
            <table class="refund-monitor-table money-table">
                <thead>
                    <tr class="refund-monitor-title-row">
                        <th rowspan="2" class="province-col">Province</th>
                        <th colspan="12">TOTAL COLLECTION C.Y. {{ $range->label($year) }}</th>
                        <th rowspan="2" class="total-col">TOTAL</th>
                    </tr>
                    <tr class="refund-monitor-month-row">
                        @foreach($months as $monthName)
                            <th>{{ $monthName }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($collection as $row)
                        <tr>
                            <th>{{ $row['province'] }}</th>
                            @for($month = 1; $month <= 12; $month++)
                                <td>{{ $money($row['m'.$month]) }}</td>
                            @endfor
                            <td class="total-cell">{{ $money($row['total']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="refund-monitor-total-row">
                        <th>Total</th>
                        @for($month = 1; $month <= 12; $month++)
                            <td>{{ $money($collectionTotals['m'.$month]) }}</td>
                        @endfor
                        <td>{{ $money($collectionTotals['total']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel refund-monitor-sheet">
        <div class="refund-monitor-table-wrap">
            <table class="refund-monitor-table money-table">
                <thead>
                    <tr class="refund-monitor-title-row">
                        <th rowspan="2" class="province-col">Province</th>
                        <th colspan="12">TOTAL RECEIVABLE C.Y. {{ $range->label($year) }}</th>
                        <th rowspan="2" class="total-col">TOTAL</th>
                    </tr>
                    <tr class="refund-monitor-month-row">
                        @foreach($months as $monthName)
                            <th>{{ $monthName }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($receivable as $row)
                        <tr>
                            <th>{{ $row['province'] }}</th>
                            @for($month = 1; $month <= 12; $month++)
                                <td>{{ $money($row['m'.$month]) }}</td>
                            @endfor
                            <td class="total-cell">{{ $money($row['total']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="refund-monitor-total-row">
                        <th>Total</th>
                        @for($month = 1; $month <= 12; $month++)
                            <td>{{ $money($receivableTotals['m'.$month]) }}</td>
                        @endfor
                        <td>{{ $money($receivableTotals['total']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel refund-monitor-sheet refund-rate-sheet">
        <div class="refund-monitor-table-wrap">
            <table class="refund-monitor-table rate-table">
                <thead>
                    <tr class="refund-monitor-title-row">
                        <th rowspan="2" class="province-col">Particulars</th>
                        <th colspan="12">REFUND RATE C.Y. {{ $range->label($year) }}</th>
                        <th rowspan="2" class="total-col">TOTAL</th>
                    </tr>
                    <tr class="refund-monitor-month-row">
                        @foreach($months as $monthName)
                            <th>{{ $monthName }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($refundRates as $row)
                        <tr>
                            <th>{{ $row['province'] }}</th>
                            @for($month = 1; $month <= 12; $month++)
                                <td>{{ $percent($row['m'.$month]) }}</td>
                            @endfor
                            <td class="total-cell">{{ $percent($row['total']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="refund-monitor-monthly-rate">
                        <th>MONTHLY</th>
                        @for($month = 1; $month <= 12; $month++)
                            <td>{{ $percent($monthlyRates['m'.$month]) }}</td>
                        @endfor
                        <td class="total-cell">{{ $percent($monthlyRates['total']) }}</td>
                    </tr>
                    <tr class="refund-monitor-quarterly-rate">
                        <th>QUARTERLY</th>
                        <td colspan="3"><span>Q1</span>{{ $percent($quarterlyRates['q1']) }}</td>
                        <td colspan="3"><span>Q2</span>{{ $percent($quarterlyRates['q2']) }}</td>
                        <td colspan="3"><span>Q3</span>{{ $percent($quarterlyRates['q3']) }}</td>
                        <td colspan="3"><span>Q4</span>{{ $percent($quarterlyRates['q4']) }}</td>
                        <td class="total-cell">{{ $percent($quarterlyRates['total']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <p class="refund-monitor-note">
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        Collection is grouped by OR Date when available; saved payments without an OR Date use their rental due month. Receivable follows the current rental schedule, including Reconstruction/Revision and revised Amount Due.
    </p>
</div>
@endsection

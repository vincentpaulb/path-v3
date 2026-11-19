@extends('layouts.app')
@section('title','My Assigned Proposals')
@section('page-title','Evaluator Portal')

@section('content')
<div class="page-intro evaluator-intro">
    <div>
        <h1>My assigned proposals</h1>
        <p>{{ $evaluator->full_name }} · {{ $evaluator->designation }} · {{ $evaluator->organization }}</p>
    </div>
</div>

<div class="metric-grid evaluator-metrics">
    @foreach(['Assigned','Started','Ongoing','Approved'] as $status)
        <article class="metric compact">
            <span class="metric-icon evaluator-metric-status eval-status-{{ strtolower($status) }}">
                @if($status === 'Approved') ✓ @elseif($status === 'Ongoing') ↻ @elseif($status === 'Started') ▶ @else A @endif
            </span>
            <div>
                <small>{{ $status }}</small>
                <strong>{{ number_format((int) ($statusCounts[$status] ?? 0)) }}</strong>
                <em>assigned proposal{{ (int) ($statusCounts[$status] ?? 0) === 1 ? '' : 's' }}</em>
            </div>
        </article>
    @endforeach
</div>

<section class="panel table-panel">
    <div class="panel-head evaluator-toolbar">
        <div>
            <span class="eyebrow">ASSIGNED TO YOU</span>
            <h2>Proposal queue</h2>
        </div>
        <form method="GET" class="evaluator-filters">
            <input type="search" name="search" value="{{ request('search') }}" placeholder="Search proposal or proponent…">
            <select name="status" onchange="this.form.submit()">
                <option value="">All evaluation statuses</option>
                @foreach(['Assigned','Started','Ongoing','Approved'] as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
                @endforeach
            </select>
            
        @include('partials.date-range-filter', ['dateBasis' => 'Submission date', 'overridesYear' => false])
        <button class="btn" type="submit">Search</button>
            @if(request()->hasAny(['search','status']))
                <a class="btn" href="{{ route('evaluator.proposals.index') }}">Reset</a>
            @endif
        </form>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Proposal</th>
                    <th>Proponent</th>
                    <th>Sector</th>
                    <th>Submitted</th>
                    <th>My status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($proposals as $proposal)
                    <tr>
                        <td>
                            <b>{{ $proposal->project_title }}</b>
                            <small class="table-subtext">{{ $proposal->province }}</small>
                        </td>
                        <td>{{ $proposal->proponent }}</td>
                        <td><span class="sector-badge {{ strtolower($proposal->sector) }}">{{ $proposal->sector }}</span></td>
                        <td>{{ $proposal->date_submitted?->format('M d, Y') }}</td>
                        <td>
                            @if(auth()->user()->hasPermission('evaluator_portal.update_status'))
                                <form method="POST" action="{{ route('evaluator.proposals.status',$proposal) }}">
                                    @csrf
                                    @method('PATCH')
                                    <select class="evaluator-status-select evaluator-status-field eval-status-{{ strtolower($proposal->pivot->evaluation_status ?: 'Assigned') }}" name="evaluation_status" onchange="this.form.submit()">
                                        @foreach(['Started','Ongoing','Approved'] as $status)
                                            <option value="{{ $status }}" @selected($proposal->pivot->evaluation_status === $status)>{{ $status }}</option>
                                        @endforeach
                                        @if($proposal->pivot->evaluation_status === 'Assigned')
                                            <option value="" selected disabled>Assigned · choose status</option>
                                        @endif
                                    </select>
                                </form>
                            @else
                                <span class="eval-status-badge eval-status-{{ strtolower($proposal->pivot->evaluation_status ?: 'Assigned') }}">{{ $proposal->pivot->evaluation_status ?: 'Assigned' }}</span>
                            @endif
                        </td>
                        <td><a class="row-action" href="{{ route('evaluator.proposals.show',$proposal) }}">Review proposal →</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty">No proposals are assigned to you.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $proposals->links() }}
</section>
@endsection

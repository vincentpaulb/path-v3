@extends('layouts.app')
@section('title','Evaluator Details')
@section('page-title','Evaluator Details')
@section('top-back')
    <a class="back topbar-back" href="{{ route('evaluators.index') }}">← Back to evaluators</a>
@endsection
@section('content')

<section class="panel">
    <div class="panel-head">
        <div class="profile-banner">
            <div class="avatar large">{{ strtoupper(substr($evaluator->first_name,0,1).substr($evaluator->last_name,0,1)) }}</div>
            <div>
                <h1>{{ $evaluator->full_name }}</h1>
                <p>{{ $evaluator->designation ?: 'Evaluator' }}</p>
                <span class="role-chip">Evaluator</span>
            </div>
        </div>

        <div class="form-actions">
            @if(auth()->user()->hasPermission('evaluators.edit'))
                <a class="btn" href="{{ route('evaluators.edit',$evaluator) }}"><i class="fa-solid fa-pen" aria-hidden="true"></i> Edit evaluator</a>
            @endif

            @if(auth()->user()->hasPermission('evaluators.archive'))
                <form method="POST" action="{{ route('evaluators.destroy',$evaluator) }}"
                      data-confirm-title="Move evaluator to Archives?"
                      data-confirm-message="{{ $evaluator->full_name }} will be removed from the active evaluator directory and the linked portal account will be suspended. Assignments and historical records will be preserved for restoration."
                      data-confirm-button="Move to Archives">
                    @csrf
                    @method('DELETE')
                    <button class="btn danger" type="submit"><i class="fa-solid fa-box-archive" aria-hidden="true"></i> Move to Archives</button>
                </form>
            @endif
        </div>
    </div>

    <dl class="detail-grid">
        <div><dt>Classification</dt><dd>{{ $evaluator->classification ?: '—' }}</dd></div>
        <div><dt>Sex</dt><dd>{{ $evaluator->sex ?: '—' }}</dd></div>
        <div><dt>Email</dt><dd>{{ $evaluator->email ?: '—' }}</dd></div>
        <div><dt>Contact number</dt><dd>{{ $evaluator->contact_number ?: '—' }}</dd></div>
        <div><dt>Organization</dt><dd>{{ $evaluator->organization ?: '—' }}</dd></div>
        <div><dt>Designation</dt><dd>{{ $evaluator->designation ?: '—' }}</dd></div>
        <div><dt>Portal username</dt><dd>{{ $evaluator->account?->username ?: 'Account not created' }}</dd></div>
        <div><dt>Portal role</dt><dd>{{ $evaluator->account?->role ?: '—' }}</dd></div>
    </dl>
</section>
@endsection

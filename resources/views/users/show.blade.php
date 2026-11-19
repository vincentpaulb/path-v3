@extends('layouts.app')
@section('title','User Details')
@section('page-title','User Details')
@section('top-back')
    <a class="back topbar-back" href="{{ route('users.index') }}">← Back to users</a>
@endsection
@section('content')

<section class="panel">
    <div class="panel-head">
        <div class="profile-banner">
            <div class="avatar large">{{ strtoupper(substr($user->first_name,0,1).substr($user->last_name,0,1)) }}</div>
            <div>
                <h1>{{ $user->full_name }}</h1>
                <p>{{ $user->job_desc ?: 'PATH user' }}</p>
                <span class="role-chip">{{ $user->accessRole?->name ?? $user->role }}</span>
            </div>
        </div>

        <div class="form-actions">
            @if($user->isEvaluator())
                @if(auth()->user()->hasPermission('evaluators.view'))
                    <a class="btn ghost" href="{{ route('evaluators.index') }}">Manage in Evaluators</a>
                @endif
            @else
                @if(auth()->user()->hasPermission('users.edit') && (auth()->user()->isAdmin() || !$user->isAdmin()))
                    <a class="btn" href="{{ route('users.edit',$user) }}"><i class="fa-solid fa-pen" aria-hidden="true"></i> Edit user</a>
                @endif

                @if(auth()->user()->hasPermission('users.archive') && !$user->is(auth()->user()) && (auth()->user()->isAdmin() || !$user->isAdmin()))
                    <form method="POST" action="{{ route('users.destroy',$user) }}"
                          data-confirm-title="Move user to Archives?"
                          data-confirm-message="{{ $user->full_name }}'s account will be suspended and moved to Archives. Historical PATH records remain intact and the account can be restored later."
                          data-confirm-button="Move to Archives">
                        @csrf
                        @method('DELETE')
                        <button class="btn danger" type="submit"><i class="fa-solid fa-box-archive" aria-hidden="true"></i> Move to Archives</button>
                    </form>
                @endif
            @endif
        </div>
    </div>

    <dl class="detail-grid">
        <div><dt>Username</dt><dd>{{ $user->username }}</dd></div>
        <div><dt>Email</dt><dd>{{ $user->email }}</dd></div>
        <div><dt>Contact number</dt><dd>{{ $user->mobile_number ?: '—' }}</dd></div>
        <div><dt>Province</dt><dd>{{ $user->province ?: 'All / N/A' }}</dd></div>
        <div><dt>Organization</dt><dd>{{ $user->company ?: '—' }}</dd></div>
        <div><dt>Designation</dt><dd>{{ $user->job_desc ?: '—' }}</dd></div>
        <div><dt>Role</dt><dd>{{ $user->accessRole?->name ?? $user->role }}</dd></div>
        <div><dt>Data scope</dt><dd>{{ match($user->dataScope()) {'province' => 'Assigned province only', 'assigned' => 'Assigned proposals only', default => 'Global'} }}</dd></div>
    </dl>

    @if($user->accessRole)
        <div class="user-permission-summary">
            <span class="eyebrow">ROLE ACCESS</span>
            <p>{{ $user->accessRole->description ?: 'Access is controlled by the assigned role.' }}</p>
            @if(auth()->user()->isAdmin())
                <a href="{{ route('roles.index',['role'=>$user->accessRole->id]) }}">Review role permissions →</a>
            @endif
        </div>
    @endif
</section>
@endsection

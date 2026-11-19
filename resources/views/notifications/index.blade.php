@extends('layouts.app')
@section('title', 'Notifications')
@section('page-title', 'Notifications')

@section('top-actions')
    @if(auth()->user()->unreadNotifications()->exists())
        <form method="POST" action="{{ route('notifications.read-all') }}">
            @csrf
            <button class="btn" type="submit"><i class="fa-solid fa-check-double"></i> Mark all as read</button>
        </form>
    @endif
@endsection

@section('content')
<div class="page-intro">
    <div>
        <h1>Proposal notifications</h1>
        <p>Updates for proposals assigned or available to your account.</p>
    </div>
</div>

<section class="panel notification-history-panel">
    <div class="notification-history-list">
        @forelse($notifications as $notification)
            <form method="POST" action="{{ route('notifications.open', $notification->id) }}">
                @csrf
                <button type="submit" class="notification-history-item {{ $notification->read_at ? '' : 'is-unread' }}">
                    <span class="notification-history-icon"><i class="fa-solid fa-file-lines"></i></span>
                    <span class="notification-history-copy">
                        <span><b>{{ $notification->data['action'] ?? 'Proposal updated' }}</b>@if(!$notification->read_at)<em>Unread</em>@endif</span>
                        <strong>{{ $notification->data['proponent'] ?? 'Proposal' }}</strong>
                        <small>{{ \Illuminate\Support\Str::limit($notification->data['proposal_title'] ?? '', 140) }}</small>
                        <time>{{ $notification->data['actor_name'] ?? 'PATH user' }} · {{ $notification->created_at->diffForHumans() }}</time>
                    </span>
                    <i class="fa-solid fa-chevron-right notification-history-arrow"></i>
                </button>
            </form>
        @empty
            <div class="notification-history-empty">
                <i class="fa-regular fa-bell-slash"></i>
                <b>No notifications yet</b>
                <span>Proposal updates assigned to you will appear here.</span>
            </div>
        @endforelse
    </div>
    {{ $notifications->links() }}
</section>
@endsection

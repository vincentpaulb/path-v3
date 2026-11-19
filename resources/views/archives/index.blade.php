@extends('layouts.app')
@section('title','Archives')
@section('page-title','Archives')

@section('content')
@php
    $labels = [
        'proposals' => ['label' => 'Proposals', 'icon' => 'fa-file-lines'],
        'evaluators' => ['label' => 'Evaluators', 'icon' => 'fa-user-check'],
        'users' => ['label' => 'Users', 'icon' => 'fa-users'],
        'required-documents' => ['label' => 'Required Documents', 'icon' => 'fa-list-check'],
    ];
    $activeLabel = $labels[$section]['label'];
@endphp

<div class="archive-page">
    <div class="page-intro">
        <div class="archive-description">
            <h1>Archives</h1>
        </div>
        <div class="summary-pill">
            <span>{{ strtoupper($activeLabel) }}</span>
            <b>{{ number_format($counts[$section] ?? 0) }}</b>
        </div>
    </div>

    <div class="archive-tabs" role="navigation" aria-label="Archive categories">
        @foreach($allowed as $tab)
            <a class="archive-tab {{ $section === $tab ? 'active' : '' }}" href="{{ route('archives.index',['section'=>$tab]) }}">
                <i class="fa-solid {{ $labels[$tab]['icon'] }}" aria-hidden="true"></i>
                {{ $labels[$tab]['label'] }}
                <span class="archive-count">{{ $counts[$tab] ?? 0 }}</span>
            </a>
        @endforeach
    </div>

    <div class="archive-toolbar">
        <form method="GET" action="{{ route('archives.index',['section'=>$section]) }}" class="archive-search">
            <input name="search" value="{{ $search }}" placeholder="Search archived {{ strtolower($activeLabel) }}..." aria-label="Search archived {{ strtolower($activeLabel) }}">
            
        @include('partials.date-range-filter', ['dateBasis' => 'Archive date', 'overridesYear' => false])
        <button class="btn" type="submit"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Search</button>
            @if($search !== '')<a class="btn ghost" href="{{ route('archives.index',['section'=>$section]) }}">Clear</a>@endif
        </form>
        <span class="archive-note">Newest archived records are shown first.</span>
    </div>

    <section class="panel table-panel">
        <div class="table-wrap">
            @if($section === 'proposals')
                <table class="archive-table">
                    <thead><tr><th>Proposal</th><th>Sector / Province</th><th>Amount</th><th>Archived by</th><th>Archived</th><th></th></tr></thead>
                    <tbody>
                    @forelse($items as $item)
                        <tr>
                            <td><span class="archive-primary">{{ $item->project_title }}</span><span class="archive-meta">{{ $item->proponent }}</span></td>
                            <td><span class="sector-badge {{ strtolower($item->sector) }}">{{ $item->sector }}</span><span class="archive-meta">{{ $item->province }}</span></td>
                            <td>₱{{ number_format((float)$item->amount,2) }}</td>
                            <td><div class="archive-actor"><b>{{ $item->deletedBy?->full_name ?? 'System / unavailable user' }}</b><small>{{ $item->deletedBy?->role ?? '—' }}</small></div></td>
                            <td>{{ $item->deleted_at?->format('M d, Y') }}<small>{{ $item->deleted_at?->format('g:i A') }}</small></td>
                            <td><div class="archive-actions">
                                @if(auth()->user()->hasPermission('proposals.restore'))
                                    <form method="POST" action="{{ route('archives.proposals.restore',$item->id) }}">@csrf<button class="btn compact archive-restore" type="submit"><i class="fa-solid fa-rotate-left"></i> Restore</button></form>
                                @endif
                                @if(auth()->user()->isAdmin())
                                    <form method="POST" action="{{ route('archives.proposals.force-delete',$item->id) }}" data-confirm-title="Permanently delete proposal?" data-confirm-message="This will permanently remove {{ $item->project_title }} and all related evaluations, comments, files, and history. This cannot be undone." data-confirm-button="Delete permanently">@csrf @method('DELETE')<button class="btn compact archive-permanent" type="submit">Delete permanently</button></form>
                                @endif
                            </div></td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><div class="archive-empty"><i class="fa-solid fa-box-open"></i><h3>No archived proposals</h3><p>Archived proposals will appear here.</p></div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            @elseif($section === 'evaluators')
                <table class="archive-table">
                    <thead><tr><th>Evaluator</th><th>Classification</th><th>Organization</th><th>Portal access</th><th>Archived by</th><th>Archived</th><th></th></tr></thead>
                    <tbody>
                    @forelse($items as $item)
                        <tr>
                            <td><span class="archive-primary">{{ $item->full_name }}</span><span class="archive-meta">{{ $item->email }}</span></td>
                            <td><span class="role-chip">{{ $item->classification }}</span><span class="archive-meta">{{ $item->designation }}</span></td>
                            <td>{{ $item->organization }}</td>
                            <td><span class="archive-state suspended">Suspended</span><span class="archive-meta">{{ $item->account?->username ?: 'No linked account' }}</span></td>
                            <td><div class="archive-actor"><b>{{ $item->deletedBy?->full_name ?? 'System / unavailable user' }}</b><small>{{ $item->deletedBy?->role ?? '—' }}</small></div></td>
                            <td>{{ $item->deleted_at?->format('M d, Y') }}<small>{{ $item->deleted_at?->format('g:i A') }}</small></td>
                            <td><div class="archive-actions">
                                @if(auth()->user()->hasPermission('evaluators.restore'))
                                    <form method="POST" action="{{ route('archives.evaluators.restore',$item->id) }}">@csrf<button class="btn compact archive-restore" type="submit"><i class="fa-solid fa-rotate-left"></i> Restore</button></form>
                                @endif
                                @if(auth()->user()->isAdmin())
                                    <form method="POST" action="{{ route('archives.evaluators.force-delete',$item->id) }}" data-confirm-title="Permanently delete evaluator?" data-confirm-message="This permanently removes {{ $item->full_name }} from the evaluator directory and removes evaluator assignments/remarks tied to that directory record. This cannot be undone." data-confirm-button="Delete permanently">@csrf @method('DELETE')<button class="btn compact archive-permanent" type="submit">Delete permanently</button></form>
                                @endif
                            </div></td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><div class="archive-empty"><i class="fa-solid fa-box-open"></i><h3>No archived evaluators</h3><p>Archived evaluators will appear here.</p></div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            @elseif($section === 'users')
                <table class="archive-table">
                    <thead><tr><th>User</th><th>Username</th><th>Role</th><th>Organization</th><th>Archived by</th><th>Archived</th><th></th></tr></thead>
                    <tbody>
                    @forelse($items as $item)
                        <tr>
                            <td><span class="archive-primary">{{ $item->full_name }}</span><span class="archive-meta">{{ $item->email }}</span></td>
                            <td>{{ $item->username }}</td>
                            <td><span class="role-chip">{{ $item->role }}</span><span class="archive-meta">{{ $item->province ?: 'All provinces' }}</span></td>
                            <td>{{ $item->company ?: '—' }}</td>
                            <td><div class="archive-actor"><b>{{ $item->deletedBy?->full_name ?? 'System / unavailable user' }}</b><small>{{ $item->deletedBy?->role ?? '—' }}</small></div></td>
                            <td>{{ $item->deleted_at?->format('M d, Y') }}<small>{{ $item->deleted_at?->format('g:i A') }}</small></td>
                            <td><div class="archive-actions">
                                @if(auth()->user()->hasPermission('users.restore'))
                                    <form method="POST" action="{{ route('archives.users.restore',$item->id) }}">@csrf<button class="btn compact archive-restore" type="submit"><i class="fa-solid fa-rotate-left"></i> Restore</button></form>
                                @endif
                                @if(auth()->user()->isAdmin())
                                    <form method="POST" action="{{ route('archives.users.force-delete',$item->id) }}" data-confirm-title="Permanently delete user?" data-confirm-message="This will permanently remove {{ $item->full_name }}'s account if no historical PATH records still reference it. This cannot be undone." data-confirm-button="Delete permanently">@csrf @method('DELETE')<button class="btn compact archive-permanent" type="submit">Delete permanently</button></form>
                                @endif
                            </div></td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><div class="archive-empty"><i class="fa-solid fa-box-open"></i><h3>No archived users</h3><p>Archived user accounts will appear here.</p></div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            @else
                <table class="archive-table">
                    <thead><tr><th style="width:70px">#</th><th>Required document</th><th>Archived by</th><th>Archived</th><th></th></tr></thead>
                    <tbody>
                    @forelse($items as $item)
                        <tr>
                            <td>{{ ($items->firstItem() ?? 1) + $loop->index }}</td>
                            <td><span class="archive-primary">{{ $item->name }}</span><span class="archive-meta">Removed from the active proposal checklist while archived</span></td>
                            <td><div class="archive-actor"><b>{{ $item->deletedBy?->full_name ?? 'System / unavailable user' }}</b><small>{{ $item->deletedBy?->role ?? '—' }}</small></div></td>
                            <td>{{ $item->deleted_at?->format('M d, Y') }}<small>{{ $item->deleted_at?->format('g:i A') }}</small></td>
                            <td><div class="archive-actions">
                                @if(auth()->user()->hasPermission('required_documents.restore'))
                                    <form method="POST" action="{{ route('archives.required-documents.restore',$item->id) }}">@csrf<button class="btn compact archive-restore" type="submit"><i class="fa-solid fa-rotate-left"></i> Restore</button></form>
                                @endif
                                @if(auth()->user()->isAdmin())
                                    <form method="POST" action="{{ route('archives.required-documents.force-delete',$item->id) }}" data-confirm-title="Permanently delete required document?" data-confirm-message="This permanently removes {{ $item->name }} from the master Required Documents records. Existing proposal review text is preserved. This cannot be undone." data-confirm-button="Delete permanently">@csrf @method('DELETE')<button class="btn compact archive-permanent" type="submit">Delete permanently</button></form>
                                @endif
                            </div></td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><div class="archive-empty"><i class="fa-solid fa-box-open"></i><h3>No archived required documents</h3><p>Archived checklist items will appear here.</p></div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            @endif
        </div>

        @if($items->hasPages())
            <div class="pagination">{{ $items->links() }}</div>
        @endif
    </section>
</div>
@endsection

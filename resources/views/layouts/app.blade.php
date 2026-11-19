<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title','PATH') · DOST VII</title>

    <link rel="stylesheet" href="{{ asset('css/path.css') }}">
    <link rel="stylesheet" href="{{ asset('css/path-overrides.css') }}?v={{ filemtime(public_path('css/path-overrides.css')) }}">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    @stack('styles')
    <link rel="stylesheet" href="{{ asset('css/path-responsive.css') }}?v={{ filemtime(public_path('css/path-responsive.css')) }}">

</head>
<body>
    <a class="skip-link" href="#mainContent">Skip to main content</a>
    <div class="app-shell">
        <div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>
        <aside class="sidebar" id="sidebar" aria-label="Primary navigation">
            <a class="brand" href="{{ route(auth()->user()->homeRouteName()) }}">
                <div class="brand-logo-wrap">
                    <img class="brand-logo" src="{{ asset('images/path-logo.png') }}" alt="PATH logo">
                </div>
                <div class="brand-copy">
                    <b>PATH</b>
                    <small>Proposal Administration<br>& Tracking Hub</small>
                </div>
            </a>

            @php
                $navUser = auth()->user();
                $showAdministration = $navUser->hasAnyPermission(['users.view','evaluators.view','provinces.view']) || $navUser->isAdmin();
                $showMonitoring = $navUser->hasAnyPermission([
                    'monitoring.rtec.view',
                    'monitoring.rental.view',
                    'monitoring.refund_rate.view',
                    'monitoring.liquidation.view',
                ]);
                $showDocumentControl = $navUser->hasAnyPermission(['required_documents.view', 'industry_sectors.view']);
                $showRecords = $navUser->hasPermission('archives.view') && $navUser->hasAnyPermission([
                    'proposals.archive','proposals.restore','evaluators.archive','evaluators.restore',
                    'users.archive','users.restore','required_documents.archive','required_documents.restore'
                ]);
                $fundedProposalContext = request()->routeIs('proposals.show')
                    && request('from') === 'funded'
                    && isset($proposal)
                    && $proposal->isFunded();
            @endphp
            <nav>
                <span class="nav-label">WORKSPACE</span>

                @if($navUser->hasPermission('dashboard.view'))
                    <a class="nav-link {{ request()->routeIs('dashboard')?'active':'' }}" href="{{ route('dashboard') }}">
                        <i class="fa-solid fa-house" aria-hidden="true"></i>
                        Overview
                    </a>
                @endif

                @if($navUser->isEvaluator() && $navUser->hasPermission('evaluator_portal.view'))
                    <a class="nav-link {{ request()->routeIs('evaluator.proposals.*')?'active':'' }}" href="{{ route('evaluator.proposals.index') }}">
                        <i class="fa-solid fa-clipboard-check" aria-hidden="true"></i>
                        My Assigned Proposals
                    </a>
                @endif

                @if($navUser->hasPermission('proposals.view') && !$navUser->isEvaluator())
                    <a class="nav-link {{ request()->routeIs('proposals.*') && !$fundedProposalContext ? 'active' : '' }}" href="{{ route('proposals.index') }}">
                        <i class="fa-solid fa-file-lines" aria-hidden="true"></i>
                        Proposals
                    </a>
                @endif

                @if($navUser->hasPermission('funded.view'))
                    <a class="nav-link {{ request()->routeIs('funded.*') || $fundedProposalContext ? 'active' : '' }}" href="{{ route('funded.index') }}">
                        <i class="fa-solid fa-money-bill-wave" aria-hidden="true"></i>
                        Funded Proposals
                    </a>
                @endif

                @if($navUser->hasPermission('analytics.view'))
                    <a class="nav-link {{ request()->routeIs('analytics.*')?'active':'' }}" href="{{ route('analytics.index') }}">
                        <i class="fa-solid fa-chart-bar" aria-hidden="true"></i>
                        Analytics
                    </a>
                @endif

                @if($showMonitoring)
                    <span class="nav-label">MONITORING</span>
                    @if($navUser->hasPermission('monitoring.rtec.view'))
                        <a class="nav-link {{ request()->routeIs('monitoring.rtec')?'active':'' }}" href="{{ route('monitoring.rtec') }}">
                            <i class="fa-solid fa-clipboard-list" aria-hidden="true"></i>
                            RTEC Monitoring
                        </a>
                    @endif
                    @if($navUser->hasPermission('monitoring.rental.view'))
                        <a class="nav-link {{ request()->routeIs('monitoring.rental')?'active':'' }}" href="{{ route('monitoring.rental') }}">
                            <i class="fa-solid fa-receipt" aria-hidden="true"></i>
                            Rental Monitoring
                        </a>
                    @endif
                    @if($navUser->hasPermission('monitoring.refund_rate.view'))
                        <a class="nav-link {{ request()->routeIs('monitoring.refund-rate')?'active':'' }}" href="{{ route('monitoring.refund-rate') }}">
                            <i class="fa-solid fa-percent" aria-hidden="true"></i>
                            Refund Rate Monitoring
                        </a>
                    @endif
                    @if($navUser->hasPermission('monitoring.liquidation.view'))
                        <a class="nav-link {{ request()->routeIs('monitoring.liquidation*')?'active':'' }}" href="{{ route('monitoring.liquidation') }}">
                            <i class="fa-solid fa-file-invoice-dollar" aria-hidden="true"></i>
                            Liquidation Monitoring
                        </a>
                    @endif
                @endif

                @if($showDocumentControl)
                    <span class="nav-label">DOCUMENT CONTROL</span>
                    @if($navUser->hasPermission('required_documents.view'))
                    <a class="nav-link {{ request()->routeIs('required-documents.*')?'active':'' }}" href="{{ route('required-documents.index') }}">
                        <i class="fa-solid fa-list-check" aria-hidden="true"></i>
                        Required Documents
                    </a>
                    @endif
                    @if($navUser->hasPermission('industry_sectors.view'))
                    <a class="nav-link {{ request()->routeIs('industry-sectors.*')?'active':'' }}" href="{{ route('industry-sectors.index') }}">
                        <i class="fa-solid fa-industry" aria-hidden="true"></i>
                        Industry Sectors
                    </a>
                    @endif
                @endif

                @if($showAdministration)
                    <span class="nav-label">ADMINISTRATION</span>
                    @if($navUser->hasPermission('users.view'))
                        <a class="nav-link {{ request()->routeIs('users.*')?'active':'' }}" href="{{ route('users.index') }}">
                            <i class="fa-solid fa-users" aria-hidden="true"></i>
                            User Management
                        </a>
                    @endif
                    @if($navUser->hasPermission('evaluators.view'))
                        <a class="nav-link {{ request()->routeIs('evaluators.*')?'active':'' }}" href="{{ route('evaluators.index') }}">
                            <i class="fa-solid fa-user-check" aria-hidden="true"></i>
                            Evaluators
                        </a>
                    @endif
                    @if($navUser->hasPermission('provinces.view'))
                        <a class="nav-link {{ request()->routeIs('provinces.*')?'active':'' }}" href="{{ route('provinces.index') }}">
                            <i class="fa-solid fa-map-location-dot" aria-hidden="true"></i>
                            Provinces
                        </a>
                    @endif
                    @if($navUser->isAdmin())
                        <a class="nav-link {{ request()->routeIs('public-directory.*')?'active':'' }}" href="{{ route('public-directory.review') }}">
                            <i class="fa-solid fa-map" aria-hidden="true"></i>
                            Public Directory
                        </a>
                        <a class="nav-link {{ request()->routeIs('roles.*')?'active':'' }}" href="{{ route('roles.index') }}">
                            <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                            Roles & Permissions
                        </a>
                    @endif
                @endif

                @if($showRecords)
                    <span class="nav-label">RECORDS</span>
                    <a class="nav-link {{ request()->routeIs('archives.*')?'active':'' }}" href="{{ route('archives.index') }}">
                        <i class="fa-solid fa-box-archive" aria-hidden="true"></i>
                        Archives
                    </a>
                @endif

                <span class="nav-label">GOVERNANCE</span>
                <a class="nav-link {{ request()->routeIs('compliance.*') ? 'active' : '' }}" href="{{ route('compliance.index') }}">
                    <i class="fa-solid fa-scale-balanced" aria-hidden="true"></i>
                    Compliance Center
                </a>
            </nav>

            <div class="sidebar-footer">
                <div class="mini-user">
                    <div class="avatar">{{ strtoupper(substr(auth()->user()->first_name,0,1).substr(auth()->user()->last_name,0,1)) }}</div>
                    <div>
                        <b>{{ auth()->user()->full_name }}</b>
                        <small>{{ auth()->user()->accessRole?->name ?? auth()->user()->role }}{{ auth()->user()->province?' · '.auth()->user()->province:'' }}</small>
                    </div>
                </div>

                <div class="sidebar-actions">
                    <a href="{{ route('profile.edit') }}">Profile</a>
                    <form method="POST" action="{{ route('logout') }}" style="display:inline">@csrf<button class="link">Sign out</button></form>
                </div>
            </div>
        </aside>

        <div class="main">
            <header class="topbar">
                <div class="top-left">
                    <button class="menu-toggle" id="menuToggle" type="button" aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false"><i class="fa-solid fa-bars" aria-hidden="true"></i></button>
                    <div class="page-title">
                        <span class="eyebrow">DOST CENTRAL VISAYAS</span>
                        <b>@yield('page-title','PATH')</b>
                    </div>
                </div>
                <div class="top-actions">
                    @yield('top-back')
                    @php
                        $topbarNotifications = auth()->user()->notifications()->latest()->limit(6)->get();
                        $topbarUnreadCount = auth()->user()->unreadNotifications()->count();
                    @endphp
                    <details class="path-notification-menu" data-notification-status-url="{{ route('notifications.status') }}" data-notification-ticket-url="{{ route('notifications.stream-ticket') }}">
                        <summary class="path-notification-trigger" aria-label="Notifications{{ $topbarUnreadCount ? ', '.$topbarUnreadCount.' unread' : '' }}">
                            <i class="fa-solid fa-bell" aria-hidden="true"></i>
                            @if($topbarUnreadCount > 0)
                                <span>{{ $topbarUnreadCount > 99 ? '99+' : $topbarUnreadCount }}</span>
                            @endif
                        </summary>
                        <div class="path-notification-dropdown">
                            <div class="path-notification-head">
                                <div><span class="eyebrow">UPDATES</span><b>Notifications</b></div>
                                @if($topbarUnreadCount > 0)
                                    <form method="POST" action="{{ route('notifications.read-all') }}">@csrf<button type="submit">Mark all read</button></form>
                                @endif
                            </div>
                            <div class="path-notification-list">
                                @forelse($topbarNotifications as $notification)
                                    <form method="POST" action="{{ route('notifications.open', $notification->id) }}">
                                        @csrf
                                        <button type="submit" data-notification-id="{{ $notification->id }}" class="path-notification-item {{ $notification->read_at ? '' : 'is-unread' }}">
                                            <span class="path-notification-dot" aria-hidden="true"></span>
                                            <span>
                                                <b>{{ $notification->data['action'] ?? 'Proposal updated' }}</b>
                                                <small>{{ $notification->data['proponent'] ?? 'Proposal' }} · {{ $notification->data['actor_name'] ?? 'PATH user' }}</small>
                                                <time>{{ $notification->created_at->diffForHumans(null, true) }}</time>
                                            </span>
                                        </button>
                                    </form>
                                @empty
                                    <div class="path-notification-empty"><i class="fa-regular fa-bell-slash"></i><span>No notifications yet.</span></div>
                                @endforelse
                            </div>
                            <div class="path-webpush" data-webpush-panel data-webpush-url="{{ route('notifications.push.status') }}" data-webpush-worker="{{ asset('path-push-sw.js') }}">
                                <button type="button" data-webpush-toggle disabled>Enable desktop alerts</button>
                                <p data-webpush-message role="status">Checking desktop alert support…</p>
                            </div>
                            <a class="path-notification-all" href="{{ route('notifications.index') }}">View all notifications <i class="fa-solid fa-arrow-right"></i></a>
                        </div>
                    </details>
                    <span class="date-chip">{{ now()->format('D, M j, Y') }}</span>
                    @yield('top-actions')
                </div>
            </header>

            <main class="content-area" id="mainContent" tabindex="-1">
                @php
                    $toastMessages = collect();
                    foreach (['success', 'error', 'warning', 'info'] as $flashType) {
                        if (session()->has($flashType)) {
                            $toastMessages->push([
                                'type' => $flashType,
                                'message' => session($flashType),
                            ]);
                        }
                    }
                    $validationErrors = $errors ?? null;
                    if ($validationErrors instanceof \Illuminate\Support\ViewErrorBag && $validationErrors->any()) {
                        foreach ($validationErrors->all() as $validationMessage) {
                            $toastMessages->push([
                                'type' => 'error',
                                'message' => $validationMessage,
                            ]);
                        }
                    }
                @endphp

                @if($toastMessages->isNotEmpty())
                    <div class="path-toast-stack" id="pathToastStack" aria-live="polite" aria-atomic="true">
                        @foreach($toastMessages as $index => $toast)
                            <div class="path-toast path-toast-{{ $toast['type'] }}" data-autohide="6800" style="--toast-enter-delay: {{ $index * 90 }}ms;">
                                <div class="path-toast-icon" aria-hidden="true">
                                    <i class="fa-solid {{ $toast['type'] === 'success' ? 'fa-circle-check' : ($toast['type'] === 'warning' ? 'fa-triangle-exclamation' : ($toast['type'] === 'info' ? 'fa-circle-info' : 'fa-circle-exclamation')) }}"></i>
                                </div>
                                <div class="path-toast-body">
                                    <strong>{{ $toast['type'] === 'success' ? 'Success' : ($toast['type'] === 'warning' ? 'Notice' : ($toast['type'] === 'info' ? 'Information' : 'Action needed')) }}</strong>
                                    <span>{{ $toast['message'] }}</span>
                                </div>
                                <button type="button" class="path-toast-close" aria-label="Dismiss notification">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                                <span class="path-toast-progress" aria-hidden="true"></span>
                            </div>
                        @endforeach
                    </div>
                @endif

                @yield('content')
                <footer class="app-legal-footer" aria-label="Legal information">
                    <span>PATH · DOST VII</span>
                    <nav>
                        <a href="{{ route('legal.privacy') }}">Privacy Policy</a>
                        <a href="{{ route('legal.terms') }}">Terms and Conditions</a>
                        <a href="{{ route('legal.cookies') }}">Cookie Policy</a>
                        <a href="{{ route('legal.accessibility') }}">Accessibility</a>
                    </nav>
                </footer>
            </main>
        </div>
    </div>

    <dialog id="pathConfirmModal" class="modal path-confirm-modal" aria-labelledby="pathConfirmTitle" aria-describedby="pathConfirmMessage">
        <div class="modal-card path-confirm-card">
            <div class="path-confirm-copy">
                <span class="eyebrow">CONFIRM ACTION</span>
                <h2 id="pathConfirmTitle">Are you sure?</h2>
                <p id="pathConfirmMessage">This action cannot be undone.</p>
            </div>
            <div class="path-confirm-actions">
                <button type="button" class="btn ghost" id="pathConfirmCancel">Cancel</button>
                <button type="button" class="btn path-confirm-danger" id="pathConfirmSubmit"><span>Confirm</span></button>
            </div>
        </div>
    </dialog>

    <script>
        (function(){
            const stack = document.getElementById('pathToastStack');
            if (!stack) return;
            const toasts = [...stack.querySelectorAll('.path-toast')];

            const dismissToast = (toast) => {
                if (!toast || toast.dataset.dismissing === 'true') return;
                toast.dataset.dismissing = 'true';
                toast.classList.add('is-leaving');
                window.setTimeout(() => {
                    toast.remove();
                    if (!stack.children.length) stack.remove();
                }, 260);
            };

            toasts.forEach((toast, index) => {
                window.setTimeout(() => toast.classList.add('is-visible'), 40 + (index * 90));
                const delay = Number(toast.dataset.autohide || 6800);
                const timer = window.setTimeout(() => dismissToast(toast), delay + (index * 90));
                toast.dataset.timerId = String(timer);

                toast.querySelector('.path-toast-close')?.addEventListener('click', () => {
                    window.clearTimeout(Number(toast.dataset.timerId));
                    dismissToast(toast);
                });

                toast.addEventListener('mouseenter', () => {
                    window.clearTimeout(Number(toast.dataset.timerId));
                    toast.classList.add('is-paused');
                });
                toast.addEventListener('mouseleave', () => {
                    toast.classList.remove('is-paused');
                    const resumedTimer = window.setTimeout(() => dismissToast(toast), 1400);
                    toast.dataset.timerId = String(resumedTimer);
                });
            });
        })();
    </script>

    <script>
        (function(){
            const modal = document.getElementById('pathConfirmModal');
            const title = document.getElementById('pathConfirmTitle');
            const message = document.getElementById('pathConfirmMessage');
            const cancel = document.getElementById('pathConfirmCancel');
            const confirm = document.getElementById('pathConfirmSubmit');
            const confirmLabel = confirm?.querySelector('span');
            let pendingForm = null;
            let trigger = null;

            function closeModal() {
                if (modal?.open) modal.close();
                pendingForm = null;
                if (trigger instanceof HTMLElement) trigger.focus();
                trigger = null;
            }

            document.addEventListener('submit', event => {
                const form = event.target.closest('form[data-confirm-message]');
                if (!form || form.dataset.confirmed === 'true') return;

                event.preventDefault();
                pendingForm = form;
                trigger = event.submitter || document.activeElement;
                title.textContent = form.dataset.confirmTitle || 'Confirm action?';
                message.textContent = form.dataset.confirmMessage || 'Please confirm that you want to continue.';
                if (confirmLabel) confirmLabel.textContent = form.dataset.confirmButton || 'Confirm';
                const variant = form.dataset.confirmVariant || 'danger';
                confirm?.classList.toggle('path-confirm-danger', variant !== 'success');
                confirm?.classList.toggle('path-confirm-success', variant === 'success');
                modal.showModal();
                window.setTimeout(() => cancel?.focus(), 0);
            });

            cancel?.addEventListener('click', closeModal);
            modal?.addEventListener('cancel', event => {
                event.preventDefault();
                closeModal();
            });
            modal?.addEventListener('click', event => {
                if (event.target === modal) closeModal();
            });
            confirm?.addEventListener('click', () => {
                if (!pendingForm) return closeModal();
                const form = pendingForm;
                form.dataset.confirmed = 'true';
                modal.close();
                form.requestSubmit();
            });
        })();
    </script>

    <script>
        (function(){
            const btn = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');
            const backdrop = document.getElementById('sidebarBackdrop');
            const mobileQuery = window.matchMedia('(max-width: 900px)');

            function setDrawer(open) {
                sidebar.classList.toggle('open', open);
                document.body.classList.toggle('drawer-open', open);
                btn.setAttribute('aria-expanded', String(open));
                btn.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
            }

            btn?.addEventListener('click', function(){
                if (mobileQuery.matches) {
                    setDrawer(!sidebar.classList.contains('open'));
                    return;
                }
                document.body.classList.toggle('collapsed');
            });
            backdrop?.addEventListener('click', () => setDrawer(false));
            sidebar?.querySelectorAll('a').forEach(link => link.addEventListener('click', () => {
                if (mobileQuery.matches) setDrawer(false);
            }));
            document.addEventListener('keydown', event => {
                if (event.key === 'Escape' && mobileQuery.matches) setDrawer(false);
            });
            mobileQuery.addEventListener('change', () => setDrawer(false));
        })();
    </script>
    @if(request()->routeIs('proposals.show','evaluator.proposals.show'))
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.querySelector('.inline-form form[action*="missing-documents"]');
            const description = form?.querySelector('textarea[name="description"]');
            if (!form || !description) return;
            const evaluationStatus = form.querySelector('textarea[name="evaluation_status"]');
            const moaCheck = form.querySelector('textarea[name="moa_check"]');
            const evaluatorAssignments = @json(isset($proposal) ? $proposal->evaluators->map(fn ($evaluator) => ['name' => $evaluator->full_name, 'classification' => $evaluator->classification, 'status' => $evaluator->pivot->evaluation_status ?? 'Assigned'])->values() : []);
            const previousLackingText = @json(isset($proposal) && $proposal->missingDocuments->isNotEmpty() ? $proposal->missingDocuments->sortByDesc('created_at')->first()->description : null);
            const previousLacking = new Set((previousLackingText || '').split('\n').map(item => item.trim()).filter(Boolean));

            const documents = @json(isset($requiredDocuments) ? $requiredDocuments->values() : []);
            const descriptionLabel = description.closest('label');
            description.required = false;
            const checklist = document.createElement('div');
            checklist.className = 'document-checklist';
            checklist.setAttribute('role', 'group');
            checklist.setAttribute('aria-label', 'Required documents checklist');

            const escapeHtml = value => String(value).replace(/[&<>"']/g, character => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            })[character]);

            if (documents.length) {
                checklist.innerHTML = '<p class="document-checklist-help">Check documents that have already been submitted. The master list is managed under Required Documents; unchecked items will be recorded as lacking.</p>' +
                    documents.map((document, index) => `<label><input type="checkbox" value="${index}" ${previousLackingText !== null && !previousLacking.has(document) ? 'checked' : ''}><span>${escapeHtml(document)}</span></label>`).join('');
                descriptionLabel.style.display = 'none';
            } else {
                checklist.innerHTML = '<p class="document-checklist-help document-checklist-empty">No required documents are configured yet. Add checklist items from Document Control → Required Documents before recording a lacking-document evaluation.</p>';
                descriptionLabel.style.display = 'none';
            }

            descriptionLabel.after(checklist);
            if (evaluationStatus) {
                const statusLabel = evaluationStatus.closest('label');
                evaluationStatus.required = false;
                const statusList = document.createElement('div');
                statusList.className = 'evaluator-status-list';
                statusList.innerHTML = evaluatorAssignments.length
                    ? evaluatorAssignments.map(evaluator => `<div><span>${evaluator.name}<em>${evaluator.classification}</em></span><b class="evaluator-state eval-status-${String(evaluator.status || 'Assigned').toLowerCase()}"><i></i>${evaluator.status}</b></div>`).join('')
                    : '<p>No evaluators are assigned to this proposal yet.</p>';
                statusLabel.after(statusList);
                statusLabel.style.display = 'none';
            }
            if (moaCheck) {
                const moaCheckLabel = moaCheck.closest('label');
                moaCheckLabel.style.display = 'none';
            }
            form.addEventListener('submit', event => {
                if (!documents.length) {
                    event.preventDefault();
                    alert('No required documents are configured. Add at least one item under Required Documents first.');
                    return;
                }

                const lacking = [...checklist.querySelectorAll('input:not(:checked)')];
                if (!lacking.length) {
                    event.preventDefault();
                    alert('All documents are marked as submitted. There are no lacking documents to record.');
                    return;
                }
                description.value = lacking.map(input => documents[Number(input.value)]).join('\n');
                if (evaluationStatus) evaluationStatus.value = evaluatorAssignments.length
                    ? evaluatorAssignments.map(evaluator => `${evaluator.name} (${evaluator.classification}): ${evaluator.status}`).join('\n')
                    : 'No evaluator assigned';
                if (moaCheck) moaCheck.value = '';
            });
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const timeline = document.querySelector('.timeline');
            const entries = [...(timeline?.querySelectorAll('article') ?? [])];
            if (!timeline || !entries.length) return;
            const evaluatorClasses = new Map(Object.entries(@json(isset($proposal) ? $proposal->evaluators->mapWithKeys(fn ($evaluator) => [$evaluator->full_name => $evaluator->classification])->all() : [])));

            const reviewTargetId = timeline.id;
            timeline.removeAttribute('id');
            const table = document.createElement('div');
            table.className = 'document-review-table table-wrap';
            table.id = reviewTargetId;
            table.classList.add('notification-target');
            const tableElement = document.createElement('table');
            tableElement.innerHTML = '<thead><tr><th>Lacking documents</th><th>Evaluation status</th><th>Recorded</th></tr></thead>';
            const body = document.createElement('tbody');

            entries.forEach((entry, entryIndex) => {
                const row = document.createElement('tr');
                if (entryIndex > 0) row.classList.add('document-review-extra');
                const documents = (entry.querySelector('b')?.textContent || '').split('\n').filter(Boolean);
                const statuses = (entry.querySelector('p')?.textContent || '').split('\n').filter(Boolean);
                const recorded = entry.querySelector('time')?.textContent || '—';
                const documentCell = document.createElement('td');
                const list = document.createElement('ul');
                list.className = 'document-lines';
                documents.forEach(item => { const line = document.createElement('li'); line.textContent = item; list.appendChild(line); });
                documentCell.appendChild(list);
                const statusCell = document.createElement('td');
                const statusList = document.createElement('div'); statusList.className = 'status-lines';
                const groups = {Internal: [], External: [], Other: []};
                statuses.forEach(item => {
                    const match = item.match(/\((Internal|External)\):\s*(.+)$/);
                    const name = item.split(':')[0].replace(/\s*\((Internal|External)\)$/, '').trim();
                    groups[match ? match[1] : (evaluatorClasses.get(name) || 'Other')].push(item);
                });
                ['Internal', 'External', 'Other'].forEach(group => {
                    if (!groups[group].length) return;
                    const section = document.createElement('div'); section.className = 'status-group';
                    if (group !== 'Other') { const heading = document.createElement('strong'); heading.textContent = `${group} evaluators`; section.appendChild(heading); }
                    groups[group].forEach(item => { const line = document.createElement('span'); line.textContent = item; section.appendChild(line); });
                    statusList.appendChild(section);
                });
                statusCell.appendChild(statusList);
                [documentCell, statusCell, recorded].forEach((value, index) => {
                    if (index < 2) row.appendChild(value);
                    else { const cell = document.createElement('td'); cell.textContent = value; row.appendChild(cell); }
                });
                body.appendChild(row);
            });
            tableElement.appendChild(body);
            table.appendChild(tableElement);

            if (entries.length > 1) {
                const actions = document.createElement('div');
                actions.className = 'document-review-actions';
                const toggle = document.createElement('button');
                toggle.type = 'button';
                toggle.className = 'document-review-toggle';
                toggle.textContent = 'Show More';
                toggle.setAttribute('aria-expanded', 'false');
                toggle.addEventListener('click', () => {
                    const expanded = table.classList.toggle('is-expanded');
                    toggle.textContent = expanded ? 'Show Less' : 'Show More';
                    toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                });
                actions.appendChild(toggle);
                table.appendChild(actions);
            }

            timeline.replaceWith(table);
        });
    </script>
    @if(!auth()->user()->isEvaluator() && auth()->user()->hasPermission('proposals.workflow'))
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const uploadBox = document.querySelector('.upload-box');
            if (uploadBox) {
                uploadBox.classList.add('feature-unavailable');
                uploadBox.querySelectorAll('input, button').forEach(control => control.disabled = true);
                const notice = document.createElement('p');
                notice.className = 'unavailable-note';
                notice.innerHTML = '<i class="fa-solid fa-clock"></i> File upload is not yet available.';
                uploadBox.prepend(notice);
            }
        });
    </script>
    @endif
    @endif
    <script src="{{ asset('js/path-password-toggle.js') }}" defer></script>
    <script src="{{ asset('js/path-async-pagination.js') }}?v={{ filemtime(public_path('js/path-async-pagination.js')) }}" defer></script>
    <script src="{{ asset('js/path-notifications.js') }}?v={{ filemtime(public_path('js/path-notifications.js')) }}" defer></script>
    <script src="{{ asset('js/path-webpush.js') }}?v={{ filemtime(public_path('js/path-webpush.js')) }}" defer></script>
    @stack('scripts')
</body>
</html>

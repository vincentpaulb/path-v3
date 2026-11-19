(function () {
    const menu = document.querySelector('[data-notification-status-url]');
    if (!menu) return;

    const trigger = menu.querySelector('.path-notification-trigger');
    const list = menu.querySelector('.path-notification-list');
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let knownIds = new Set(
        Array.from(list?.querySelectorAll('[data-notification-id]') || [], item => item.dataset.notificationId)
    );
    let audioContext = null;
    let activeIncomingPopup = null;
    let additionalIncomingCount = 0;
    const connectionStatus = document.createElement('p');
    connectionStatus.className = 'path-notification-connection';
    connectionStatus.setAttribute('role', 'status');
    list?.before(connectionStatus);
    const setConnectionState = state => {
        menu.dataset.notificationConnection = state;
        connectionStatus.hidden = state === 'connected';
        connectionStatus.textContent = {
            connecting: 'Connecting to live notifications…',
            connected: '',
            reconnecting: 'Live notifications disconnected. Reconnecting…',
            offline: 'You are offline. Notifications will resume when connected.',
            expired: 'Your session has expired. Reload the page to reconnect.',
            unsupported: 'This browser cannot connect to live notifications.'
        }[state];
    };
    setConnectionState('connecting');

    const getAudioContext = () => {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        if (!AudioContext) return null;
        audioContext ||= new AudioContext();
        return audioContext;
    };

    const unlockNotificationSound = () => {
        const context = getAudioContext();
        if (context?.state === 'suspended') context.resume().catch(() => {});
    };

    // Browsers require one user interaction before allowing page audio.
    document.addEventListener('pointerdown', unlockNotificationSound, { once: true, passive: true });
    document.addEventListener('keydown', unlockNotificationSound, { once: true });

    const playNotificationSound = () => {
        const context = getAudioContext();
        if (!context) return;

        const play = () => {
            const start = context.currentTime + 0.02;
            [[784, start], [1046.5, start + 0.14]].forEach(([frequency, time]) => {
                const oscillator = context.createOscillator();
                const gain = context.createGain();
                oscillator.type = 'sine';
                oscillator.frequency.setValueAtTime(frequency, time);
                gain.gain.setValueAtTime(0.0001, time);
                gain.gain.exponentialRampToValueAtTime(0.055, time + 0.018);
                gain.gain.exponentialRampToValueAtTime(0.0001, time + 0.22);
                oscillator.connect(gain).connect(context.destination);
                oscillator.start(time);
                oscillator.stop(time + 0.23);
            });
        };

        if (context.state === 'suspended') {
            context.resume().then(play).catch(() => {});
        } else {
            play();
        }
    };

    const makeItem = notification => {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = notification.open_url;

        const csrf = document.createElement('input');
        csrf.type = 'hidden';
        csrf.name = '_token';
        csrf.value = token;

        const button = document.createElement('button');
        button.type = 'submit';
        button.dataset.notificationId = notification.id;
        button.className = `path-notification-item${notification.unread ? ' is-unread' : ''}`;

        const dot = document.createElement('span');
        dot.className = 'path-notification-dot';
        dot.setAttribute('aria-hidden', 'true');

        const copy = document.createElement('span');
        const action = document.createElement('b');
        action.textContent = notification.action;
        const detail = document.createElement('small');
        detail.textContent = `${notification.proponent} · ${notification.actor_name}`;
        const time = document.createElement('time');
        time.textContent = notification.time;
        copy.append(action, detail, time);
        button.append(dot, copy);
        form.append(csrf, button);
        return form;
    };

    const dismissIncoming = () => {
        const popup = activeIncomingPopup;
        if (!popup) return;
        activeIncomingPopup = null;
        additionalIncomingCount = 0;
        popup.classList.add('is-leaving');
        window.setTimeout(() => popup.remove(), 180);
    };

    const showIncoming = (notification, count) => {
        // Audio restrictions must never prevent the visual notification.
        try { playNotificationSound(); } catch (_) { /* The popup still appears. */ }

        if (activeIncomingPopup) {
            additionalIncomingCount += count;
            const more = activeIncomingPopup.querySelector('.path-incoming-more');
            if (more) {
                more.hidden = false;
                more.textContent = `+${additionalIncomingCount} more new update${additionalIncomingCount === 1 ? '' : 's'}`;
            }
            activeIncomingPopup.classList.remove('has-newer-update');
            window.requestAnimationFrame(() => activeIncomingPopup?.classList.add('has-newer-update'));
            return;
        }

        additionalIncomingCount = Math.max(0, count - 1);

        const popup = document.createElement('section');
        popup.className = 'path-incoming-notification';
        popup.setAttribute('role', 'alert');
        popup.setAttribute('aria-live', 'assertive');

        const head = document.createElement('div');
        head.className = 'path-incoming-head';
        const label = document.createElement('span');
        label.innerHTML = '<i class="fa-solid fa-bell"></i> New notification';
        const close = document.createElement('button');
        close.type = 'button';
        close.setAttribute('aria-label', 'Dismiss notification');
        close.innerHTML = '<i class="fa-solid fa-xmark"></i>';
        close.addEventListener('click', dismissIncoming);
        head.append(label, close);

        const action = document.createElement('h2');
        action.textContent = notification.action;
        const proponent = document.createElement('p');
        proponent.textContent = notification.proponent;
        const meta = document.createElement('small');
        meta.textContent = `${notification.actor_name} · ${notification.time}`;

        const footer = document.createElement('div');
        footer.className = 'path-incoming-actions';
        const more = document.createElement('span');
        more.className = 'path-incoming-more';
        more.hidden = additionalIncomingCount === 0;
        more.textContent = `+${additionalIncomingCount} more new update${additionalIncomingCount === 1 ? '' : 's'}`;
        footer.appendChild(more);

        const buttons = document.createElement('div');
        buttons.className = 'path-incoming-button-group';

        const okay = document.createElement('button');
        okay.type = 'button';
        okay.className = 'path-incoming-okay';
        okay.textContent = 'Okay';
        okay.addEventListener('click', dismissIncoming);
        buttons.appendChild(okay);

        const openForm = document.createElement('form');
        openForm.method = 'POST';
        openForm.action = notification.open_url;
        const csrf = document.createElement('input');
        csrf.type = 'hidden';
        csrf.name = '_token';
        csrf.value = token;
        const openButton = document.createElement('button');
        openButton.type = 'submit';
        openButton.textContent = 'View update';
        openForm.append(csrf, openButton);
        buttons.appendChild(openForm);
        footer.appendChild(buttons);

        popup.append(head, action, proponent, meta, footer);
        document.body.appendChild(popup);
        activeIncomingPopup = popup;
        window.requestAnimationFrame(() => popup.classList.add('is-visible'));
    };

    const render = payload => {
        const unread = Number(payload.unread_count || 0);
        let badge = trigger.querySelector(':scope > span');
        if (unread > 0) {
            if (!badge) {
                badge = document.createElement('span');
                trigger.appendChild(badge);
            }
            badge.textContent = unread > 99 ? '99+' : String(unread);
        } else {
            badge?.remove();
        }
        trigger.setAttribute('aria-label', unread ? `Notifications, ${unread} unread` : 'Notifications');

        if (!Array.isArray(payload.notifications) || !list) return;
        list.replaceChildren();
        if (payload.notifications.length) {
            payload.notifications.forEach(notification => list.appendChild(makeItem(notification)));
        } else {
            const empty = document.createElement('div');
            empty.className = 'path-notification-empty';
            empty.innerHTML = '<i class="fa-regular fa-bell-slash"></i><span>No notifications yet.</span>';
            list.appendChild(empty);
        }
    };

    // A PHP SSE process sends invalidations; no periodic status polling.
    let stream = null;
    let reconnectTimer = null;
    let refreshTimer = null;
    let reconnectDelay = 1000;
    let refreshDelay = 1000;
    let requestInFlight = false;
    let refreshPending = false;
    let connecting = false;
    let stopped = false;
    let generation = 0;

    const disconnect = () => {
        generation += 1;
        stream?.close();
        stream = null;
        window.clearTimeout(reconnectTimer);
        window.clearTimeout(refreshTimer);
    };

    const stop = () => {
        stopped = true;
        disconnect();
        setConnectionState('expired');
    };

    const scheduleRefresh = (wait = 250) => {
        refreshPending = true;
        if (stopped || !navigator.onLine || requestInFlight) return;
        window.clearTimeout(refreshTimer);
        refreshTimer = window.setTimeout(refresh, wait);
    };

    const refresh = async () => {
        if (stopped || !navigator.onLine || requestInFlight) return;
        requestInFlight = true;
        refreshPending = false;

        try {
            const response = await fetch(menu.dataset.notificationStatusUrl, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store',
                signal: AbortSignal.timeout(15000)
            });

            if ([401, 403, 419].includes(response.status) || response.redirected) {
                stop();
                return;
            }
            if (!response.ok) throw new Error(`Notification refresh failed: ${response.status}`);
            const payload = await response.json();
            if (stopped) return;
            const incoming = Array.isArray(payload.notifications)
                ? payload.notifications.filter(notification => notification.unread && !knownIds.has(notification.id))
                : [];

            knownIds = new Set((payload.notifications || []).map(notification => notification.id));
            render(payload);
            if (incoming.length) showIncoming(incoming[0], incoming.length);
            refreshDelay = 1000;
        } catch (_) {
            refreshPending = true;
            refreshDelay = Math.min(refreshDelay * 2, 60000);
        } finally {
            requestInFlight = false;
            if (refreshPending) scheduleRefresh(refreshDelay);
        }
    };

    const scheduleReconnect = () => {
        if (stopped || !navigator.onLine) return;
        setConnectionState('reconnecting');
        window.clearTimeout(reconnectTimer);
        reconnectTimer = window.setTimeout(connect, reconnectDelay + Math.random() * 1000);
        reconnectDelay = Math.min(reconnectDelay * 2, 60000);
    };

    const connect = async () => {
        if (stopped || !navigator.onLine || connecting || stream || !window.EventSource) return;
        connecting = true;
        const attempt = generation;
        try {
            const response = await fetch(menu.dataset.notificationTicketUrl, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
                cache: 'no-store',
                signal: AbortSignal.timeout(15000)
            });
            if (attempt !== generation || stopped) return;
            if ([401, 403, 419].includes(response.status) || response.redirected) {
                stop();
                return;
            }
            if (!response.ok) throw new Error('Cannot authorize notification stream');
            const payload = await response.json();
            if (attempt !== generation || stopped) return;
            const url = new URL(payload.url, window.location.href);
            // artisan serve connects directly to the local PHP stream port.
            if (url.origin !== window.location.origin &&
                (url.hostname !== window.location.hostname || url.protocol !== window.location.protocol)) {
                throw new Error('Notification stream must use the application host and protocol');
            }
            stream = new EventSource(url.href);
            stream.addEventListener('open', () => setConnectionState('connected'));
            stream.addEventListener('changed', () => {
                setConnectionState('connected');
                reconnectDelay = 1000;
                scheduleRefresh();
            });
            stream.onerror = () => {
                stream?.close();
                stream = null;
                scheduleReconnect();
            };
        } catch (_) {
            if (attempt === generation) scheduleReconnect();
        } finally {
            connecting = false;
            if (attempt !== generation && !stopped && navigator.onLine) scheduleReconnect();
        }
    };

    // Manual opening also works while the push process is unavailable.
    menu.addEventListener('toggle', () => { if (menu.open) scheduleRefresh(0); });
    window.addEventListener('offline', () => { disconnect(); setConnectionState('offline'); });
    window.addEventListener('online', () => { reconnectDelay = 1000; connect(); });
    window.addEventListener('pagehide', disconnect);
    window.addEventListener('pageshow', () => { if (!stopped) connect(); });

    if (!window.EventSource) setConnectionState('unsupported');
    else connect();
})();

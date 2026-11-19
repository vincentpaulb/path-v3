(function () {
    const panel = document.querySelector('[data-webpush-panel]');
    if (!panel) return;
    const toggle = panel.querySelector('[data-webpush-toggle]');
    const message = panel.querySelector('[data-webpush-message]');
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let enabled = false;
    let publicKey = null;
    let busy = false;
    const say = text => { message.textContent = text; };
    const update = () => {
        toggle.textContent = enabled ? 'Turn off desktop alerts' : 'Enable desktop alerts';
        toggle.disabled = busy || !publicKey;
    };
    const api = async (url, method = 'GET', body) => {
        const response = await fetch(url, {
            method, credentials: 'same-origin', cache: 'no-store',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
            body: body === undefined ? undefined : JSON.stringify(body),
            signal: AbortSignal.timeout(15000),
        });
        if (response.redirected || [401, 419].includes(response.status)) throw new Error('Reload PATH and sign in again.');
        const payload = await response.json();
        if (!response.ok) throw new Error(payload.message || 'Unable to update desktop alerts. Try again.');
        return payload;
    };
    const keyBytes = value => {
        const raw = atob(value.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(value.length / 4) * 4, '='));
        return Uint8Array.from(raw, character => character.charCodeAt(0));
    };
    const worker = () => navigator.serviceWorker.getRegistration(panel.dataset.webpushWorker);
    const activeRegistration = async () => {
        const registration = await navigator.serviceWorker.register(panel.dataset.webpushWorker);
        const candidate = registration.installing || registration.waiting;
        if (!candidate && registration.active) return registration;
        if (!candidate) throw new Error('Desktop notification setup is not ready. Please try again.');
        await new Promise((resolve, reject) => {
            const timeout = setTimeout(() => reject(new Error('Notification setup timed out. Try again.')), 15000);
            const changed = () => {
                if (candidate.state === 'activated') { clearTimeout(timeout); resolve(); }
                if (candidate.state === 'redundant') { clearTimeout(timeout); reject(new Error('Notification setup failed. Try again.')); }
            };
            candidate.addEventListener('statechange', changed);
            changed();
        });
        return registration;
    };

    toggle.addEventListener('click', async () => {
        if (busy || !publicKey) return;
        busy = true;
        update();
        try {
            if (enabled) {
                // Revoke on the server first, even if browser unsubscribe fails.
                await api(panel.dataset.webpushUrl, 'DELETE');
                enabled = false;
                const registration = await worker();
                const subscription = await registration?.pushManager.getSubscription();
                if (subscription) await subscription.unsubscribe();
                const shown = await registration?.getNotifications();
                shown?.forEach(notification => notification.close());
                say('Desktop alerts are off for this browser.');
            } else {
                // This must run directly from the click, before other asynchronous setup.
                const permission = await Notification.requestPermission();
                if (permission !== 'granted') {
                    say('Notifications were not allowed. You can change this in your browser’s site permissions.');
                    return;
                }
                const registration = await activeRegistration();
                let subscription = await registration.pushManager.getSubscription();
                const desiredKey = keyBytes(publicKey);
                if (subscription && subscription.options.applicationServerKey) {
                    const oldKey = new Uint8Array(subscription.options.applicationServerKey);
                    if (oldKey.length !== desiredKey.length || oldKey.some((value, index) => value !== desiredKey[index])) {
                        await subscription.unsubscribe();
                        subscription = null;
                    }
                }
                subscription ||= await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: desiredKey });
                await api(panel.dataset.webpushUrl, 'POST', subscription.toJSON());
                enabled = true;
                say('Desktop alerts are enabled for this browser. Signing out turns them off.');
            }
        } catch (error) {
            say(error.message || 'Unable to set up desktop alerts. Try again.');
        } finally {
            busy = false;
            update();
        }
    });

    (async () => {
        if (!window.isSecureContext) {
            say('Desktop alerts need HTTPS. For local testing, use http://localhost:8000.');
            return;
        }
        if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
            say('Desktop alerts are not supported by this browser.');
            return;
        }
        try {
            const status = await api(panel.dataset.webpushUrl);
            if (!status.available) { say('Desktop alerts are not configured on this server yet.'); return; }
            publicKey = status.public_key;
            const registration = await worker();
            // Refresh existing subscriptions' worker code as well as newly enabled ones.
            if (registration) await registration.update();
            const subscription = await registration?.pushManager.getSubscription();
            enabled = Notification.permission === 'granted' && !!subscription && subscription.endpoint === status.endpoint;
            if (status.endpoint && !enabled) await api(panel.dataset.webpushUrl, 'DELETE');
            say(enabled ? 'Desktop alerts are enabled for this browser.' : 'Allow desktop alerts through your browser’s push service, even when PATH is closed.');
            update();
        } catch (error) { say(error.message || 'Could not load desktop notification settings. Reload to retry.'); }
    })();
})();

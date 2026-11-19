/* PATH Web Push only: no offline cache or fetch interception. */
self.addEventListener('install', event => event.waitUntil(self.skipWaiting()));
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));

async function reportToPages(tag, state, error = '') {
    try {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const client of windows) {
            if (client.url.startsWith(self.registration.scope)) {
                client.postMessage({ type: 'path-push-status', tag, state, error });
            }
        }
    } catch (_) { /* A closed page must not interfere with desktop display. */ }
}

self.addEventListener('push', event => {
    let payload = {};
    try { payload = event.data?.json() || {}; } catch (_) { /* Use generic text. */ }
    const tag = typeof payload.tag === 'string' ? payload.tag : 'path-update';
    event.waitUntil((async () => {
        await reportToPages(tag, 'received');
        try {
            await self.registration.showNotification(
        typeof payload.title === 'string' ? payload.title : 'New PATH notification',
        {
            body: typeof payload.body === 'string' ? payload.body : 'Open PATH to view your notifications.',
            tag,
            renotify: false,
            data: { url: new URL('notifications', self.registration.scope).href },
        }
            );
            await reportToPages(tag, 'shown');
        } catch (error) {
            await reportToPages(tag, 'failed', error.name || 'NotificationError');
        }
    })());
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    // Always use our own scope, never a URL supplied in a push message.
    const target = new URL('notifications', self.registration.scope).href;
    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const existing = windows.find(client => client.url.startsWith(self.registration.scope));
        if (existing) {
            const navigated = await existing.navigate(target);
            if (navigated) return navigated.focus();
        }
        return self.clients.openWindow(target);
    })());
});

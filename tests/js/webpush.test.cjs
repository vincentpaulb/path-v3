const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function ui(options = {}) {
    const calls = [];
    const button = () => ({ disabled: true, events: {}, addEventListener(name, fn) { this.events[name] = fn; } });
    const toggle = button();
    const testButton = button();
    const localButton = button();
    const message = {};
    const serviceWorkerEvents = {};
    const localAlerts = [];
    let permissionRequests = 0;
    const subscription = {
        endpoint: 'https://fcm.googleapis.com/fcm/send/test', options: {},
        toJSON: () => ({ endpoint: 'https://fcm.googleapis.com/fcm/send/test', keys: {} }),
        unsubscribe: async () => { calls.push('unsubscribe'); },
    };
    const registration = {
        active: true,
        scope: 'https://path.test/',
        update: async () => {},
        showNotification: async (...args) => { localAlerts.push(args); },
        pushManager: { getSubscription: async () => options.enabled ? subscription : null, subscribe: async () => { calls.push('subscribe'); return subscription; } },
        getNotifications: async () => [],
    };
    const panel = {
        dataset: { webpushUrl: '/push', webpushTest: '/push/test', webpushWorker: '/path-push-sw.js' },
        querySelector: selector => selector.includes('toggle') ? toggle : selector.includes('local') ? localButton : selector.includes('test') ? testButton : message,
    };
    const notification = {
        permission: options.enabled ? 'granted' : 'default',
        requestPermission: async () => { permissionRequests++; return options.permission || 'granted'; },
    };
    vm.runInNewContext(fs.readFileSync('public/js/path-webpush.js', 'utf8'), {
        window: { isSecureContext: options.secure !== false, PushManager: {}, Notification: notification },
        Notification: notification, AbortSignal, Uint8Array, atob, URL,
        setTimeout: (fn, delay) => delay === 2000 ? queueMicrotask(fn) : setTimeout(fn, delay), clearTimeout,
        navigator: { serviceWorker: { addEventListener: (name, fn) => { serviceWorkerEvents[name] = fn; }, getRegistration: async () => registration, register: async () => registration } },
        document: { querySelector: selector => selector.startsWith('meta') ? { content: 'csrf' } : panel },
        fetch: async (url, init) => {
            calls.push(init.method + ' ' + url);
            if (url === '/push/test' && options.receipt !== false) serviceWorkerEvents.message({ data: { type: 'path-push-status', tag: 'test-tag', state: 'shown' } });
            return { ok: true, status: 200, json: async () => ({ available: true, public_key: 'BA', endpoint: options.enabled ? subscription.endpoint : null, tag: 'test-tag', status_url: '/push/test/status', accepted: true }) };
        },
    });
    return { calls, toggle, testButton, localButton, localAlerts, message, permissionRequests: () => permissionRequests,
        flush: async () => { for (let i = 0; i < 15; i++) await Promise.resolve(); },
    };
}

test('permission is requested only after clicking enable, and subscription is saved', async () => {
    const h = ui();
    await h.flush();
    assert.equal(h.permissionRequests(), 0);
    assert.equal(h.toggle.disabled, false);
    await h.toggle.events.click();
    assert.equal(h.permissionRequests(), 1);
    assert.deepEqual(h.calls, ['GET /push', 'subscribe', 'POST /push']);
    assert.equal(h.toggle.textContent, 'Turn off desktop alerts');
});

test('denied permission does not create a subscription', async () => {
    const h = ui({ permission: 'denied' });
    await h.flush();
    await h.toggle.events.click();
    assert.deepEqual(h.calls, ['GET /push']);
    assert.match(h.message.textContent, /not allowed/);
});

test('disabling revokes server delivery before unsubscribing the browser', async () => {
    const h = ui({ enabled: true });
    await h.flush();
    await h.toggle.events.click();
    assert.deepEqual(h.calls, ['GET /push', 'DELETE /push', 'unsubscribe']);
    assert.equal(h.toggle.textContent, 'Enable desktop alerts');
});

test('insecure origins explain HTTPS requirement without prompting', async () => {
    const h = ui({ secure: false });
    await h.flush();
    assert.equal(h.permissionRequests(), 0);
    assert.equal(h.toggle.disabled, true);
    assert.match(h.message.textContent, /HTTPS/);
});

test('service worker reports receipt and display failures to its own pages', async () => {
    const handlers = {};
    const statuses = [];
    let work;
    const self = {
        addEventListener: (name, fn) => { handlers[name] = fn; },
        registration: { scope: 'https://path.test/', showNotification: async () => { const error = new Error('blocked'); error.name = 'NotAllowedError'; throw error; } },
        clients: { matchAll: async () => [{ url: 'https://path.test/', postMessage: data => statuses.push(data) }] },
    };
    vm.runInNewContext(fs.readFileSync('public/path-push-sw.js', 'utf8'), { self, URL });
    handlers.push({ data: { json: () => ({ tag: 'tag-1' }) }, waitUntil: promise => { work = promise; } });
    await work;
    assert.deepEqual(statuses.map(data => data.state), ['received', 'failed']);
    assert.equal(statuses[1].error, 'NotAllowedError');
});

test('service worker displays push and ignores untrusted click destinations', async () => {
    const handlers = {};
    const shown = [];
    let opened;
    let work;
    const self = {
        addEventListener: (event, fn) => { handlers[event] = fn; },
        registration: { scope: 'https://path.test/', showNotification: async (...args) => { shown.push(args); } },
        clients: { matchAll: async () => [], openWindow: async url => { opened = url; } },
    };
    vm.runInNewContext(fs.readFileSync('public/path-push-sw.js', 'utf8'), { self, URL });
    handlers.push({ data: { json: () => ({ title: 'PATH update', body: 'Open PATH', tag: 'path-123', url: 'https://evil.test/' }) }, waitUntil: promise => { work = promise; } });
    await work;
    assert.equal(shown[0][0], 'PATH update');
    assert.equal(shown[0][1].tag, 'path-123');
    assert.equal(shown[0][1].renotify, false);
    handlers.notificationclick({ notification: { close() {}, data: { url: 'https://evil.test/' } }, waitUntil: promise => { work = promise; } });
    await work;
    assert.equal(opened, 'https://path.test/notifications');
});

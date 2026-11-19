const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function harness(options = {}) {
    const timers = new Map();
    const streams = [];
    const calls = [];
    const events = {};
    let timerId = 0;
    let status = 200;
    let notifications = [];
    const popups = [];
    const element = () => ({
        querySelector: () => null, querySelectorAll: () => [],
        dataset: {}, classList: { add() {}, remove() {} },
        setAttribute() {}, appendChild() {}, replaceChildren() {}, before() {}, append() {}, addEventListener() {},
    });
    const trigger = element();
    const list = element();
    const menu = {
        dataset: { notificationStatusUrl: '/status', notificationTicketUrl: '/ticket' },
        querySelector: selector => selector.includes('trigger') ? trigger : list,
        addEventListener: (name, handler) => { events[name] = handler; },
    };
    class EventSource {
        constructor(url) { this.url = url; this.events = {}; streams.push(this); }
        addEventListener(name, handler) { this.events[name] = handler; }
        close() { this.closed = true; }
    }
    const window = {
        EventSource, location: new URL(options.pageUrl || 'https://path.test/'),
        requestAnimationFrame: fn => fn(),
        AudioContext: options.audioContext,
        setTimeout: (fn, delay) => { timers.set(++timerId, { fn, delay }); return timerId; },
        clearTimeout: id => timers.delete(id),
        addEventListener: (name, handler) => { events[name] = handler; },
    };
    vm.runInNewContext(fs.readFileSync('public/js/path-notifications.js', 'utf8'), {
        window, EventSource, URL, AbortSignal, navigator: { onLine: true },
        document: {
            body: { appendChild: popup => popups.push(popup) },
            querySelector: selector => selector.startsWith('meta') ? { content: 'csrf' } : menu,
            addEventListener() {}, createElement: element,
        },
        fetch: async url => {
            calls.push(url);
            return { ok: status === 200, status, redirected: false,
                json: async () => url === '/ticket' ? { url: options.streamUrl || '/notification-events?ticket=test' } : { unread_count: notifications.length, notifications },
            };
        },
    });
    const flush = async () => { for (let i = 0; i < 10; i++) await Promise.resolve(); };
    return { calls, streams, timers, events, flush, popups, menu, setNotifications: value => { notifications = value; }, setStatus: value => { status = value; },
        runTimer: async () => {
            const [id, timer] = timers.entries().next().value;
            timers.delete(id);
            await timer.fn();
            await flush();
        },
    };
}

test('idle streams do not poll and bursts produce one summary request', async () => {
    const h = harness();
    await h.flush();
    assert.deepEqual(h.calls, ['/ticket']);
    assert.equal(h.timers.size, 0);
    h.streams[0].events.changed();
    h.streams[0].events.changed();
    assert.equal(h.timers.size, 1);
    await h.runTimer();
    assert.deepEqual(h.calls, ['/ticket', '/status']);
    assert.equal(h.timers.size, 0);
});

test('stream loss reauthorizes with backoff and catches up after reconnect', async () => {
    const h = harness();
    await h.flush();
    h.streams[0].onerror();
    assert.equal(h.streams[0].closed, true);
    assert.equal(h.timers.size, 1);
    await h.runTimer();
    assert.equal(h.streams.length, 2);
    h.streams[1].events.changed();
    await h.runTimer();
    assert.deepEqual(h.calls, ['/ticket', '/ticket', '/status']);
    assert.equal(h.timers.size, 0);
});

test('expired sessions stop the stream without retrying', async () => {
    const h = harness();
    await h.flush();
    h.setStatus(401);
    h.streams[0].events.changed();
    await h.runTimer();
    assert.equal(h.streams[0].closed, true);
    assert.equal(h.timers.size, 0);
});

test('localhost development connects directly and creates the incoming popup even when audio fails', async () => {
    const h = harness({
        pageUrl: 'http://localhost:8000/', streamUrl: 'http://localhost:8091/notification-events?ticket=test',
        audioContext: class { constructor() { throw new Error('Audio unavailable'); } },
    });
    await h.flush();
    assert.equal(h.streams.length, 1);
    h.setNotifications([{ id: 'new-id', unread: true, action: 'Updated proposal', proponent: 'Test', actor_name: 'Another user', time: 'now', open_url: '/open/new-id' }]);
    h.streams[0].events.changed();
    await h.runTimer();
    assert.equal(h.popups.length, 1);
    assert.equal(h.popups[0].className, 'path-incoming-notification');
    assert.equal(h.menu.dataset.notificationConnection, 'connected');
    h.streams[0].events.changed();
    await h.runTimer();
    assert.equal(h.popups.length, 1, 'An existing notification must not trigger a duplicate popup');
});

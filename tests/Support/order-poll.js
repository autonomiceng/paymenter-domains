// MIT, Copyright (c) 2026 Autonomic Engineering.
// Run the rendered order status script in several tabs that share one browser storage and one clock.
const assert = require('node:assert/strict');
const vm = require('node:vm');
const script = require('node:fs').readFileSync(0, 'utf8');
const state = script.match(/const state = "([a-f0-9]{64})";/)[1];
const url = JSON.parse(script.match(/const url = ("[^"]+");/)[1]);
const browser = () => {
    const world = {now: 1_000_000, store: new Map(), reads: [], answers: [], tabs: []};
    world.storage = {getItem: key => world.store.has(key) ? world.store.get(key) : null, setItem: (key, value) => { world.store.set(key, String(value)); }};
    world.open = () => {
        const tab = {timers: [], reloaded: 0, hidden: false, listeners: {}, script: {isConnected: true}};
        const window = {
            localStorage: world.storage,
            setTimeout(callback, delay) { const timer = {callback, at: world.now + delay}; tab.timers.push(timer); return timer; },
            clearTimeout(timer) { tab.timers = tab.timers.filter(entry => entry !== timer); },
            location: {reload() { tab.reloaded++; }},
        };
        const document = {currentScript: tab.script, get hidden() { return tab.hidden; },
            addEventListener(name, listener) { tab.listeners[name] = listener; }};
        tab.show = async () => { tab.hidden = false; tab.listeners.visibilitychange(); await new Promise(done => setImmediate(done)); };
        vm.runInNewContext(script, {window, document, Date: {now: () => world.now}, Number, Math, JSON, Error,
            fetch: async (address, options) => {
                assert.equal(address, url);
                assert.equal(options.credentials, 'same-origin');
                assert.equal(options.cache, 'no-store');
                world.reads.push(world.now);
                const answer = world.answers.shift() ?? {status: 200, body: {state, pending: true}};
                if (answer.error) throw new Error('offline');
                return {status: answer.status, ok: answer.status >= 200 && answer.status < 300, redirected: answer.redirected ?? false,
                    headers: {get: name => (answer.headers ?? {})[name] ?? null}, json: async () => answer.body};
            }});
        world.tabs.push(tab);
        return tab;
    };
    // Run due timers in time order across every tab, up to the given moment.
    world.until = async moment => {
        for (;;) {
            const due = world.tabs.flatMap(tab => tab.timers.map(timer => ({tab, timer}))).filter(({timer}) => timer.at <= moment)
                .sort((a, b) => a.timer.at - b.timer.at)[0];
            if (!due) break;
            world.now = Math.max(world.now, due.timer.at);
            due.tab.timers = due.tab.timers.filter(timer => timer !== due.timer);
            await due.timer.callback();
        }
        world.now = Math.max(world.now, moment);
    };
    world.idle = tab => tab.timers.length === 0;
    return world;
};
const minute = 60000;
(async () => {
    // Two tabs on one pending order share each read, and the wait grows while nothing changes.
    let world = browser();
    const a = world.open(), b = world.open();
    await world.until(world.now + 10 * minute);
    const gaps = world.reads.slice(1).map((read, index) => read - world.reads[index]);
    assert.equal(world.reads[0] - 1_000_000, 10000, 'The first read waits ten seconds');
    assert.ok(gaps.every((gap, index) => index === 0 || gap >= gaps[index - 1]), 'The wait never shrinks while nothing changes: ' + gaps);
    assert.equal(Math.max(...gaps), 60000, 'The wait stops growing at one minute');
    for (const at of world.reads) {
        assert.ok(world.reads.filter(read => read >= at && read < at + minute).length <= 6, 'Both tabs together read at most six times a minute');
    }
    assert.ok(world.reads.length <= 16, 'Ten unchanged minutes across two tabs take at most 16 reads, not 120: ' + world.reads.length);
    assert.equal(a.reloaded + b.reloaded, 0, 'Unchanged status never reloads');

    // A hidden tab stops reading until it is shown again.
    world = browser();
    const hidden = world.open();
    hidden.hidden = true;
    await world.until(world.now + 5 * minute);
    assert.equal(world.reads.length, 0, 'A hidden tab never reads');
    assert.ok(world.idle(hidden), 'A hidden tab keeps no timer running');
    await hidden.show();
    assert.equal(world.reads.length, 1, 'Showing the tab reads once at once');
    assert.ok(!world.idle(hidden), 'A shown tab keeps reading');
    await hidden.show();
    assert.equal(world.reads.length, 1, 'Showing it again before the next read is due does not read again');

    // A hidden tab beside a visible one: only the visible tab reads.
    world = browser();
    const front = world.open(), back = world.open();
    back.hidden = true;
    await world.until(world.now + 2 * minute);
    const visibleReads = world.reads.length;
    assert.ok(visibleReads >= 2, 'The visible tab keeps reading');
    await back.show();
    assert.equal(world.reads.length, visibleReads, 'A tab shown between reads waits for the shared next read');

    // Network failures, server errors and rate limits wait longer, then reading resumes.
    world = browser();
    const flaky = world.open();
    world.answers.push({error: true}, {status: 500}, {status: 429, headers: {'Retry-After': '120'}}, {status: 200, body: {state, pending: true}});
    await world.until(world.now + 10000);
    assert.equal(world.reads.length, 1, 'First read fails offline');
    await world.until(world.now + 15000);
    assert.equal(world.reads.length, 2, 'A failed read is retried after a longer wait');
    await world.until(world.now + 22500);
    assert.equal(world.reads.length, 3, 'A server error is retried after a longer wait');
    const limitedAt = world.reads[2];
    await world.until(limitedAt + 119999);
    assert.equal(world.reads.length, 3, 'A rate-limited tab waits as long as the server asks');
    await world.until(limitedAt + 120000);
    assert.equal(world.reads.length, 4, 'Reading resumes after the rate limit');
    assert.equal(flaky.reloaded, 0, 'Failures never reload the page');
    assert.ok(!world.idle(flaky), 'Reading continues after recovery');

    // Signing out or losing the domain stops every read.
    for (const answer of [{status: 401}, {status: 403}, {status: 404}, {status: 419}, {status: 200, redirected: true, body: {}}]) {
        world = browser();
        const lost = world.open();
        world.answers.push(answer);
        await world.until(world.now + 30 * minute);
        assert.equal(world.reads.length, 1, 'Lost access stops reading: ' + answer.status);
        assert.ok(world.idle(lost), 'Lost access leaves no timer: ' + answer.status);
        assert.equal(lost.reloaded, 0, 'Lost access does not reload: ' + answer.status);
    }

    // A changed order reloads the tab that read it, and the other tab reloads from the shared answer without reading.
    world = browser();
    const reader = world.open(), other = world.open();
    world.answers.push({status: 200, body: {state: 'f'.repeat(64), pending: false}});
    await world.until(world.now + 10000);
    assert.equal(world.reads.length, 1, 'One tab read the change');
    await world.until(world.now + minute);
    assert.equal(world.reads.length, 1, 'The other tab never reads again');
    assert.deepEqual([reader.reloaded, other.reloaded], [1, 1], 'Both tabs reload once to show the change');
    assert.ok(world.idle(reader) && world.idle(other), 'Reloading tabs keep no timers');
    // The reloaded page already shows the change: an older shared answer never reloads it again.
    const fresh = world.open();
    await world.until(world.now + minute);
    assert.equal(fresh.reloaded, 0, 'A page loaded after the shared answer does not reload in a loop');

    // Without browser storage each tab still backs off on its own.
    world = browser();
    world.storage.getItem = () => { throw new Error('blocked'); };
    world.storage.setItem = () => { throw new Error('blocked'); };
    world.open();
    await world.until(world.now + 10 * minute);
    assert.ok(world.reads.length <= 15, 'A tab without storage still backs off: ' + world.reads.length);
    console.log('Order status polling backs off, shares reads across tabs, pauses hidden tabs and stops on lost access.');
})().catch(error => { console.error(error); process.exitCode = 1; });

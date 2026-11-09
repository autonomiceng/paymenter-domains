// MIT, Copyright (c) 2026 Autonomic Engineering.
// Run the rendered order status script in several tabs that share one browser storage and one clock.
const assert = require('node:assert/strict');
const vm = require('node:vm');
const script = require('node:fs').readFileSync(0, 'utf8');
const state = script.match(/const state = "([a-f0-9]{64})";/)[1];
const url = JSON.parse(script.match(/const url = ("[^"]+");/)[1]);
const browser = ({locks = true} = {}) => {
    const world = {now: 1_000_000, store: new Map(), reads: [], answers: [], tabs: [], interleave: null};
    // A storage read can be overtaken by another tab before this tab writes, as in separate browser processes.
    world.storage = {getItem: key => {
        const value = world.store.has(key) ? world.store.get(key) : null;
        const other = world.interleave; world.interleave = null; if (other) other();
        return value;
    }, setItem: (key, value) => { world.store.set(key, String(value)); }};
    // One Web Lock manager shared by every tab, as in one browser profile.
    world.held = new Set();
    world.locks = locks ? {request(name, options, callback) {
        assert.deepEqual([Object.keys(options), options.ifAvailable], [['ifAvailable'], true], 'A tab never waits in line for the lock');
        if (world.held.has(name)) return Promise.resolve(callback(null));
        world.held.add(name);
        let result;
        try { result = callback({name, mode: 'exclusive'}); } catch (error) { world.held.delete(name); return Promise.reject(error); }
        return Promise.resolve(result).finally(() => world.held.delete(name));
    }} : undefined;
    world.open = () => {
        const tab = {timers: [], reloaded: 0, hidden: false, listeners: {}, script: {isConnected: true}};
        const window = {
            localStorage: world.storage,
            navigator: world.locks ? {locks: world.locks} : {},
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
                if (answer.stall) return new Promise(() => {});
                return {status: answer.status, ok: answer.status >= 200 && answer.status < 300, redirected: answer.redirected ?? false,
                    headers: {get: name => (answer.headers ?? {})[name] ?? null},
                    json: async () => { if (answer.unreadable) throw new Error('Unexpected token < in JSON'); return answer.body; }};
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
            // A read that never answers must not stop the clock for the other tabs.
            await Promise.race([due.timer.callback(), new Promise(done => setImmediate(done))]);
        }
        world.now = Math.max(world.now, moment);
    };
    world.idle = tab => tab.timers.length === 0;
    // Every waiting read is due at a real moment no later than the longest rate-limit wait.
    world.bounded = tab => tab.timers.every(timer => Number.isFinite(timer.at) && timer.at >= world.now && timer.at <= world.now + 300000);
    return world;
};
const minute = 60000;
const key = 'domains-order-status:' + url;
// One failed read waits the normal grown wait, then a valid answer resumes the usual pace without a reload.
const retriesThenRecovers = async (answer, label) => {
    const world = browser();
    const tab = world.open();
    world.answers.push(answer, {status: 200, body: {state, pending: true}});
    await world.until(world.now + 10000);
    assert.equal(world.reads.length, 1, label + ' is read once');
    assert.ok(world.bounded(tab) && !world.idle(tab), label + ' keeps one finite timer');
    const failedAt = world.reads[0];
    await world.until(failedAt + 14999);
    assert.equal(world.reads.length, 1, label + ' waits the grown wait');
    await world.until(failedAt + 15000);
    assert.equal(world.reads.length, 2, label + ' is retried after the grown wait');
    await world.until(failedAt + 15000 + 22500);
    assert.equal(world.reads.length, 3, label + ' recovers the usual pace after a valid answer');
    assert.equal(tab.reloaded, 0, label + ' never reloads the page');
    assert.ok(world.bounded(tab) && !world.idle(tab), label + ' keeps reading after recovery');
};
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

    // A timed-out request, a damaged answer, an unreadable body or a rate limit without a usable wait is retried, then reading recovers.
    await retriesThenRecovers({status: 408}, 'A request timeout');
    for (const body of [{state: 5, pending: true}, {state, pending: 'yes'}, {state}, null, []]) {
        await retriesThenRecovers({status: 200, body}, 'A damaged answer ' + JSON.stringify(body));
    }
    await retriesThenRecovers({status: 200, unreadable: true}, 'An answer that is not JSON');
    for (const after of [undefined, 'soon', 'Wed, 21 Oct 2015 07:28:00 GMT', '-5']) {
        await retriesThenRecovers({status: 429, headers: after === undefined ? {} : {'Retry-After': after}}, 'A rate limit with Retry-After ' + after);
    }
    world = browser();
    const capped = world.open();
    world.answers.push({status: 429, headers: {'Retry-After': '99999'}});
    await world.until(world.now + 10000);
    await world.until(world.reads[0] + 299999);
    assert.equal(world.reads.length, 1, 'A very long Retry-After still waits');
    await world.until(world.reads[0] + 300000);
    assert.equal(world.reads.length, 2, 'A very long Retry-After waits at most five minutes');
    assert.equal(capped.reloaded, 0, 'A rate limit never reloads');

    // A refused read stops reading for good: no timer, no reload.
    for (const status of [400, 405, 422]) {
        world = browser();
        const refused = world.open();
        world.answers.push({status});
        await world.until(world.now + 30 * minute);
        assert.equal(world.reads.length, 1, 'A refused read stops reading: ' + status);
        assert.ok(world.idle(refused), 'A refused read leaves no timer: ' + status);
        assert.equal(refused.reloaded, 0, 'A refused read does not reload: ' + status);
    }

    // A damaged saved answer from another tab never stops reading or reads without a pause.
    for (const stored of [{next: 1, delay: 'x'}, {next: 1, delay: {}}, {next: 1, delay: null}, {next: 1e15, delay: 10000},
        {next: 1_000_000 + 10 * minute, delay: 10000}, {next: '1', delay: 10000}, '{', '7']) {
        world = browser();
        world.store.set(key, typeof stored === 'string' ? stored : JSON.stringify(stored));
        const damaged = world.open();
        await world.until(world.now + 10000);
        assert.equal(world.reads.length, 1, 'A damaged saved answer still reads on time: ' + JSON.stringify(stored));
        assert.ok(world.bounded(damaged) && !world.idle(damaged), 'A damaged saved answer keeps one finite timer: ' + JSON.stringify(stored));
        await world.until(world.now + 10 * minute);
        assert.ok(world.reads.length >= 8 && world.reads.length <= 16, 'A damaged saved answer keeps the usual pace: ' + world.reads.length);
        assert.ok(world.bounded(damaged), 'A damaged saved answer never leaves an unbounded timer: ' + JSON.stringify(stored));
        assert.equal(damaged.reloaded, 0, 'A damaged saved answer never reloads');
    }

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

    // Two tabs whose timers fire together: one tab's whole reservation overtakes the other tab's storage read.
    const together = async options => {
        const world = browser(options);
        const first = world.open(), second = world.open();
        world.interleave = () => { const timer = second.timers.shift(); timer.callback(); };
        await world.until(world.now + 10000);
        await new Promise(done => setImmediate(done));
        return {world, first, second};
    };
    ({world} = await together());
    assert.equal(world.reads.length, 1, 'Two tabs firing together share one read: ' + world.reads.length);
    assert.equal(world.held.size, 0, 'The lock is released after the read');
    await world.until(world.now + 10 * minute);
    assert.ok(world.reads.length <= 16, 'Simultaneous tabs keep the shared pace: ' + world.reads.length);
    assert.ok(world.tabs.every(tab => !world.idle(tab)), 'Both tabs keep their timers');
    // Without Web Locks, or when the lock request fails, each tab still reads and backs off on its own.
    ({world} = await together({locks: false}));
    assert.ok(world.reads.length >= 1 && world.reads.length <= 2, 'Without locks, tabs firing together read at most once each');
    await world.until(world.now + 10 * minute);
    assert.ok(world.reads.length <= 30, 'Without locks, each tab still backs off: ' + world.reads.length);
    world = browser();
    world.locks.request = () => { throw new Error('locks blocked'); };
    const unlocked = world.open();
    await world.until(world.now + 10 * minute);
    assert.ok(world.reads.length >= 1 && world.reads.length <= 15, 'A failing lock request still reads and backs off: ' + world.reads.length);
    assert.ok(!world.idle(unlocked), 'A failing lock request keeps reading');

    // A read that never answers holds no lock: the other tab keeps reading, and the stalled tab gives up and reads again.
    world = browser();
    const stalled = world.open();
    world.open();
    world.answers.push({stall: true});
    await world.until(world.now + 10000);
    assert.equal(world.reads.length, 1, 'One tab starts a read that never answers');
    assert.equal(world.held.size, 0, 'A read in flight holds no lock');
    await world.until(world.now + 30000);
    assert.ok(world.reads.length >= 2, 'The other tab reads while the first read hangs: ' + world.reads.length);
    await world.until(world.now + 10 * minute);
    assert.ok(world.reads.length >= 8 && world.reads.length <= 16, 'Reading keeps the shared pace after a stalled read: ' + world.reads.length);
    assert.ok(!world.idle(stalled), 'The stalled tab keeps its timer after giving up');

    // Without browser storage each tab still backs off on its own.
    world = browser();
    world.storage.getItem = () => { throw new Error('blocked'); };
    world.storage.setItem = () => { throw new Error('blocked'); };
    world.open();
    await world.until(world.now + 10 * minute);
    assert.ok(world.reads.length <= 15, 'A tab without storage still backs off: ' + world.reads.length);
    console.log('Order status polling backs off, shares reads across tabs, pauses hidden tabs and stops on lost access or a refused read.');
})().catch(error => { console.error(error); process.exitCode = 1; });

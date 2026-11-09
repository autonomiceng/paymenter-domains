// MIT, Copyright (c) 2026 Autonomic Engineering.
// Exercise the rendered owner script across pending, slow, finished and forbidden responses.
const assert = require('node:assert/strict');
const vm = require('node:vm');
const script = require('node:fs').readFileSync(0, 'utf8');
(async () => {
    for (const status of [200, 403]) {
        let timer, reloaded = 0;
        const classes = new Set();
        const dsState = {textContent: ''};
        const control = {closest: () => ({querySelector: () => dsState}), dataset: {managementPending: 'lock', operation: '42'}, textContent: '',
            classList: {toggle(name, enabled) { if (enabled) { classes.add(name); } else { classes.delete(name); } }}};
        const row = {state: 'Unknown', id: 42, pending: true, slow: false, tone: 'info', text: 'Turning off…'};
        const delays = [];
        vm.runInNewContext(script, {
            Date,
            document: {querySelectorAll: () => [control]},
            window: {setTimeout(callback, delay) { timer = callback; delays.push(delay); }, location: {reload() { reloaded++; }}},
            fetch: async (url, options) => {
                assert.equal(url, '/domains/1/management-status');
                assert.equal(options.credentials, 'same-origin');
                assert.equal(options.cache, 'no-store');
                return {status, ok: status === 200, json: async () => ({lock: row})};
            },
        });
        assert.equal(delays.pop(), 2000);
        const tick = async () => { const callback = timer; timer = null; await callback(); };
        await tick();
        if (status === 403) { assert.equal(timer, null, 'Lost ownership stops polling'); continue; }
        assert.equal(control.textContent, row.text);
        assert.equal(dsState.textContent, 'Unknown');
        assert.equal(classes.has('domains-tone-text-warn'), false);
        assert.equal(delays.pop(), 2000);
        row.state = 'Checking the result…';
        row.slow = true;
        row.tone = 'warning';
        await tick();
        assert.equal(dsState.textContent, 'Checking the result…');
        assert.equal(classes.has('domains-tone-text-warn'), true, 'Support status gets the warning tone');
        assert.equal(delays.pop(), 10000, 'Stored slow state survives a reload');
        row.pending = false;
        await tick();
        assert.equal(reloaded, 1, 'Completion reloads confirmed values and controls');
        assert.equal(timer, null, 'Completion schedules no more polling');
    }
    // One owner page whose status reads answer in turn; a valid pending row follows the given answers.
    const page = answers => {
        const world = {timer: null, delays: [], reloaded: 0, reads: 0};
        const control = {closest: () => ({querySelector: () => null}), dataset: {managementPending: 'lock', operation: '42'}, textContent: '',
            classList: {toggle() {}}};
        world.control = control;
        vm.runInNewContext(script, {
            Date,
            document: {querySelectorAll: () => [control]},
            window: {setTimeout(callback, delay) { world.timer = callback; world.delays.push(delay); }, location: {reload() { world.reloaded++; }}},
            fetch: async () => {
                world.reads++;
                const answer = answers.shift() ?? {status: 200, body: {lock: {id: 42, pending: true, slow: false, tone: 'info', text: 'Turning off…'}}};
                if (answer.error) throw new Error('offline');
                return {status: answer.status, ok: answer.status >= 200 && answer.status < 300, redirected: answer.redirected ?? false,
                    json: async () => { if (answer.unreadable) throw new Error('Unexpected token < in JSON'); return answer.body; }};
            },
        });
        world.tick = async () => { const callback = world.timer; world.timer = null; await callback(); };
        return world;
    };
    // A sign-in or verification redirect, lost access and other refused reads stop polling without a reload.
    for (const answer of [{status: 401}, {status: 404}, {status: 400}, {status: 422}, {status: 200, redirected: true, unreadable: true}]) {
        const world = page([answer]);
        await world.tick();
        assert.equal(world.timer, null, 'A refused read stops polling: ' + JSON.stringify(answer));
        assert.equal(world.reloaded, 0, 'A refused read does not reload: ' + JSON.stringify(answer));
    }
    // A timed-out request, a rate limit, a server error, a network failure or an unreadable body is retried, then the row updates.
    for (const answer of [{status: 408}, {status: 429}, {status: 500}, {error: true}, {status: 200, unreadable: true}]) {
        const world = page([answer]);
        await world.tick();
        assert.equal(world.delays.pop(), 2000, 'A temporary failure is retried: ' + JSON.stringify(answer));
        await world.tick();
        assert.equal(world.control.textContent, 'Turning off…', 'The next valid read updates the row: ' + JSON.stringify(answer));
        assert.ok(world.timer !== null && world.reloaded === 0, 'Polling continues after recovery: ' + JSON.stringify(answer));
    }
    console.log('Owner status polling updates, slows and stops on completion, lost access or a refused read.');
})().catch(error => { console.error(error); process.exitCode = 1; });

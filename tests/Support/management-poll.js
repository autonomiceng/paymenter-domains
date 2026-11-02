// MIT, Copyright (c) 2026 Autonomic Engineering.
// Exercise the rendered owner script across pending, slow, finished and forbidden responses.
const assert = require('node:assert/strict');
const vm = require('node:vm');
const script = require('node:fs').readFileSync(0, 'utf8');
(async () => {
    for (const status of [200, 403]) {
        let timer, reloaded = 0;
        const control = {dataset: {managementPending: 'lock', operation: '42'}, textContent: ''};
        const row = {id: 42, pending: true, slow: false, text: 'Turning off…'};
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
        assert.equal(delays.pop(), 2000);
        row.slow = true;
        await tick();
        assert.equal(delays.pop(), 10000, 'Stored slow state survives a reload');
        row.pending = false;
        await tick();
        assert.equal(reloaded, 1, 'Completion reloads confirmed values and controls');
        assert.equal(timer, null, 'Completion schedules no more polling');
    }
    console.log('Owner status polling updates, slows and stops on completion or loss of ownership.');
})().catch(error => { console.error(error); process.exitCode = 1; });

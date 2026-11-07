// MIT, Copyright (c) 2026 Autonomic Engineering. Execute the real rendered pending-order script.
const assert = require('node:assert/strict');
const vm = require('node:vm');
const source = require('node:fs').readFileSync(0, 'utf8');
const initial = source.match(/const state = ("[^"]+")/);
assert.ok(initial, 'Rendered order polling script has its initial state');
const state = JSON.parse(initial[1]);
function page(answer) {
    const timers = [], element = {isConnected: true};
    let reloads = 0, reads = 0;
    vm.runInNewContext(source, {
        document: {currentScript: element},
        window: {setTimeout: (callback, delay) => {assert.equal(delay, 10000); timers.push(callback);},
            location: {reload: () => {reloads++;}}},
        fetch: async (url, options) => {
            reads++;
            assert.match(url, /\/domains\/1\/order-status$/);
            assert.equal(options.credentials, 'same-origin');
            assert.equal(options.cache, 'no-store');
            const result = answer(element);
            return {ok: true, status: 200, redirected: false, json: async () => result, ...result};
        },
    });
    assert.equal(timers.length, 1, 'Pending page schedules a status read');
    return {timers, element, step: async () => {assert.ok(timers.length); await timers.shift()();},
        reloads: () => reloads, reads: () => reads};
}
async function main() {
    let complete = false;
    const pending = page(() => ({state, pending: !complete}));
    for (let n = 0; n < 65; n++) await pending.step();
    assert.equal(pending.reloads(), 0, 'Unchanged registration keeps polling beyond ten minutes');
    complete = true;
    await pending.step();
    assert.equal(pending.reloads(), 1, 'Completion refreshes the page');
    assert.equal(pending.timers.length, 0, 'Completed page stops its timer');
    const changed = page(() => ({state: 'changed', pending: true}));
    await changed.step();
    assert.equal(changed.reloads(), 1, 'An intermediate status change refreshes the page');
    for (const status of [401, 403, 404, 419]) {
        const denied = page(() => ({ok: false, status}));
        await denied.step();
        assert.equal(denied.timers.length, 0, 'Unauthorized or missing page stops reading');
        assert.equal(denied.reloads(), 0);
    }
    const network = page(() => {throw new Error('offline');});
    await network.step();
    assert.equal(network.timers.length, 1, 'Network failure retries');
    const malformed = page(() => ({}));
    await malformed.step();
    assert.equal(malformed.reloads(), 0, 'Malformed status cannot force a refresh');
    assert.equal(malformed.timers.length, 1);
    const left = page(() => ({state, pending: false}));
    left.element.isConnected = false;
    await left.step();
    assert.equal(left.reads(), 0, 'Navigation stops pending reads');
    assert.equal(left.timers.length, 0);
    const leaving = page(element => {element.isConnected = false; return {state, pending: false};});
    await leaving.step();
    assert.equal(leaving.reloads(), 0, 'A completed read cannot reload a different page');
    console.log('Pending order refresh, long registration, completion, errors and navigation passed.');
}
main().catch(error => {console.error(error); process.exitCode = 1;});

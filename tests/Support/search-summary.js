// MIT, Copyright (c) 2026 Autonomic Engineering.
// Run the rendered search script over result rows and read what the live region announces.
const assert = require('node:assert/strict');
const vm = require('node:vm');
const {readFileSync} = require('node:fs');
const {script} = JSON.parse(readFileSync(0, 'utf8'));
const element = () => ({textContent: '', innerHTML: '', replaceChildren() {}, append() {}});
const makeRow = state => {
    const parts = new Map();
    return {
        dataset: {state, domain: 'example.com'},
        classList: {contains: () => false, toggle() {}},
        setAttribute() {}, removeAttribute() {},
        querySelector(selector) { if (!parts.has(selector)) parts.set(selector, element()); return parts.get(selector); },
    };
};
async function announce(states) {
    const live = {dataset: {}, textContent: ''};
    const pending = [...states];
    const rows = states.map(() => makeRow('idle'));
    rows.forEach((row, index) => { row.replaceWith = next => { rows[index] = next; }; });
    const list = {
        dataset: {domainsResults: '/check', requestKey: 'key'},
        addEventListener() {},
        querySelector: () => rows.find(row => row.dataset.busy) ?? null,
        querySelectorAll: selector => selector === '[data-domains-result]' ? rows
            : selector === '[data-state="idle"]' ? rows.filter(row => row.dataset.state === 'idle') : [],
    };
    const document = {
        addEventListener() {},
        getElementById: id => id === 'domains-live' ? live : null,
        querySelector: () => null,
        querySelectorAll: selector => selector === '[data-domains-results]' ? [list] : [],
        createElement: () => ({content: {}, set innerHTML(state) { this.content.firstElementChild = makeRow(state); }}),
    };
    const fetch = async () => ({ok: true, status: 200, json: async () => ({html: pending.shift()})});
    vm.runInNewContext(script, {document, fetch, window: {}, navigator: {}, console});
    await new Promise(resolve => setImmediate(resolve));
    return live.textContent;
}
(async () => {
    const none = 'None of these names are available';
    for (const states of [['in_cart'], ['in_cart', 'taken']]) {
        const text = await announce(states);
        assert.ok(!text.includes(none), `${states}: a name in the cart reads as unavailable: ${text}`);
        assert.match(text, /cart/i, `${states}: says the name is in the cart`);
    }
    assert.match(await announce(['taken']), new RegExp(none), 'Taken names still read as unavailable');
    assert.match(await announce(['available', 'in_cart']), /^1 of 2 names are available/, 'Available names still count');
    console.log('Search summary: 4 cases passed.');
})().catch(error => { console.error(error.message); process.exit(1); });

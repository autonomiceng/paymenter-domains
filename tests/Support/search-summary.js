// MIT, Copyright (c) 2026 Autonomic Engineering.
// Run the rendered search script over result rows and read what the live region announces.
const assert = require('node:assert/strict');
const vm = require('node:vm');
const {readFileSync} = require('node:fs');
const {script} = JSON.parse(readFileSync(0, 'utf8'));
const element = tag => ({tag, children: [], dataset: {},
    get textContent() { return this.children.map(child => typeof child === 'string' ? child : child.textContent).join(''); },
    set textContent(text) { this.replaceChildren(String(text)); },
    replaceChildren(...children) { this.children = children; },
    append(...children) { this.children.push(...children); }});
// Text assignment removes old nodes; replacement and appended strings contribute to the same text.
const probe = element('p'); const anchor = element('a'); anchor.textContent = 'link';
probe.textContent = 'Start'; probe.append(' ', anchor); assert.equal(probe.textContent, 'Start link');
probe.replaceChildren(anchor, '!'); assert.equal(probe.textContent, 'link!');
probe.textContent = 'Reset'; assert.deepEqual(probe.children, ['Reset']);
const makeRow = state => {
    const parts = new Map();
    return {
        dataset: {state, domain: 'example.com'},
        classList: {contains: () => false, toggle() {}},
        setAttribute() {}, removeAttribute() {},
        querySelector(selector) { if (!parts.has(selector)) parts.set(selector, element()); return parts.get(selector); },
    };
};
async function run(states, {failure, instead = false} = {}) {
    const live = {dataset: {}, textContent: ''};
    const pending = [...states];
    const rows = states.map(() => makeRow('idle'));
    rows.forEach((row, index) => { row.replaceWith = next => { rows[index] = next; }; });
    const list = {
        dataset: {domainsResults: '/check', requestKey: 'key', supportUrl: 'https://support.example.test/help', ...(instead ? {instead: '1'} : {})},
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
        createElement: tag => tag === 'template' ? ({content: {}, set innerHTML(state) { this.content.firstElementChild = makeRow(state); }}) : element(tag),
    };
    const bodies = [];
    const fetch = async (url, options) => {
        assert.equal(url, '/check'); assert.equal(options.method, 'POST');
        bodies.push(JSON.parse(options.body));
        if (failure === 'rejected') throw new Error('Synthetic request failure');
        return {ok: ![503, 429, 419].includes(failure), status: typeof failure === 'number' ? failure : 200,
            json: async () => {
                if (failure === 'malformed') throw new SyntaxError('Synthetic JSON failure');
                return failure === 'missing html' ? {} : {html: pending.shift()};
            }};
    };
    vm.runInNewContext(script, {document, fetch, window: {}, navigator: {}, console});
    await new Promise(resolve => setImmediate(resolve));
    return {text: live.textContent, rows, bodies};
}
async function announce(states) { return (await run(states)).text; }
(async () => {
    const none = 'None of these names are available';
    for (const states of [['in_cart'], ['in_cart', 'taken']]) {
        const text = await announce(states);
        assert.ok(!text.includes(none), `${states}: a name in the cart reads as unavailable: ${text}`);
        assert.match(text, /cart/i, `${states}: says the name is in the cart`);
    }
    assert.match(await announce(['taken']), new RegExp(none), 'Taken names still read as unavailable');
    assert.match(await announce(['available', 'in_cart']), /^1 of 2 names are available/, 'Available names still count');
    for (const failure of ['rejected', 503, 'malformed', 'missing html', 429, 419]) {
        const {rows} = await run(['idle'], {failure}); const row = rows[0];
        assert.equal(row.dataset.state, 'error');
        assert.equal(row.dataset.busy, undefined, 'Failure releases the busy marker');
        const note = row.querySelector('.domains-result__note');
        const expected = failure === 429 ? 'Too many searches. Wait a minute, then retry.'
            : failure === 419 ? 'This page expired. Reload to try again.'
            : 'We couldn’t check this name right now. Retry in a minute.';
        assert.equal(note.textContent, expected + ([429, 419].includes(failure) ? '' : ' Contact support'));
        const support = note.children.filter(child => typeof child !== 'string' && child.tag === 'a');
        assert.equal(support.length, [429, 419].includes(failure) ? 0 : 1, 'Support is suppressed only for pacing and expired sessions');
        if (support.length) assert.equal(support[0].href, 'https://support.example.test/help');
        const retry = row.querySelector('.domains-result__action').children[0];
        assert.equal(retry.textContent, 'Retry'); assert.equal(retry.type, 'button');
        assert.ok(Object.hasOwn(retry.dataset, 'domainsRetry'));
    }
    for (const instead of [false, true]) {
        const {bodies} = await run(['available'], {instead});
        assert.equal(bodies.length, 1);
        assert.equal(bodies[0].domain, 'example.com'); assert.equal(bodies[0]._request_key, 'key');
        assert.equal(Object.hasOwn(bodies[0], 'instead'), instead, 'Only alternative-name mode sends instead');
        if (instead) assert.equal(bodies[0].instead, 1);
    }
    console.log('Search summary, failed fetches and alternative-name bodies: 12 cases passed.');
})().catch(error => { console.error(error.message); process.exit(1); });

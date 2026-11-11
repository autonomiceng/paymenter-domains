// MIT, Copyright (c) 2026 Autonomic Engineering.
// Submit the rendered nameserver editor through its real inline script.
const assert = require('node:assert/strict');
const vm = require('node:vm');
const {form: rendered, script} = JSON.parse(require('node:fs').readFileSync(0, 'utf8'));
assert.equal(rendered.max, '12', 'The Enom editor renders its server maximum');
for (const [value, message] of [
    ['ns1.example.com', 'Enter at least 2 nameservers, one per line.'],
    [Array.from({length: Number(rendered.max) + 1}, (_, i) => `ns${i}.example.com`).join('\n'), `Enter no more than ${rendered.max} nameservers.`],
    ['ns1.example.com\nnot a host!!', "not a host!! isn't a valid hostname. Use a name like ns1.example.com."],
    ['ns1.example.com\nNS1.example.com.', 'ns1.example.com is listed more than once.'],
    ['ns1.example.com\nns2.example.com', null],
]) {
    const errors = new Map(), attrs = new Map(), behind = new Set();
    let submit, opened = 0, focused = false, prevented = false;
    const servers = {id: rendered.id, value,
        after(error) { errors.set(error.id, error); },
        setAttribute: (name, value) => attrs.set(name, value),
        getAttribute: name => attrs.get(name), focus() { focused = true; }};
    const dialog = {open: false, addEventListener() {}, querySelector: () => null,
        showModal() { opened++; this.open = true; }};
    const form = {
        dataset: {nameserversMax: rendered.max, nameserversLetterEnding: rendered.letterEnding},
        elements: {nameservers: servers},
        querySelector: selector => selector === '[data-confirm-dialog]' ? dialog : null,
        querySelectorAll: () => [],
        closest: () => ({classList: {add: name => behind.add(name)}}),
        addEventListener(name, handler) { if (name === 'submit') submit = handler; },
    };
    const document = {getElementById: id => errors.get(id) ?? null,
        createElement: () => ({setAttribute() {}}), addEventListener() {},
        querySelectorAll: selector => selector === 'form[data-confirm]' ? [form] : []};
    vm.runInNewContext(script, {document});
    assert.equal(typeof submit, 'function', 'The actual rendered script registers submit');
    submit({submitter: {hasAttribute: () => false, matches: () => false}, preventDefault() { prevented = true; }});
    assert.equal(prevented, true, 'Submit waits for correction or confirmation');
    if (message) {
        assert.equal(errors.get(servers.id + '-client-error')?.textContent, message, 'The inline error uses the server words');
        assert.equal(attrs.get('aria-invalid'), 'true');
        assert.equal(attrs.get('aria-describedby'), servers.id + '-client-error');
        assert.equal(focused, true);
        assert.equal(opened, 0, 'Invalid nameservers never open the warning');
        assert.equal(behind.size, 0, 'The editor stays visible');
    } else {
        assert.equal(errors.size, 0);
        assert.equal(opened, 1, 'Valid nameservers open the warning');
        assert.equal(behind.has('domains-edit--behind'), true);
    }
}
console.log('Nameserver submit: 5 cases passed.');

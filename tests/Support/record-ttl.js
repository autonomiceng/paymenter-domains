// MIT, Copyright (c) 2026 Autonomic Engineering.
// Run the rendered client script against rendered saved DNS rows: the TTL cell follows the selected type.
const assert = require('node:assert/strict');
const vm = require('node:vm');
const {readFileSync} = require('node:fs');
const {forms, script} = JSON.parse(readFileSync(0, 'utf8'));
const dns = forms.filter(form => form.rows.some(row => row.ttl));
assert.equal(dns.length, 1, 'Only the DNS form shows saved TTLs');
const rows = dns[0].rows.map(row => {
    assert.ok(row.ttl, 'Every saved DNS row carries the TTL it keeps');
    const ttl = {dataset: {savedTtl: row.ttl['data-saved-ttl'], savedType: row.ttl['data-saved-type']}, textContent: row.ttl['data-saved-ttl']};
    const priority = {disabled: false};
    const listeners = {};
    const select = {value: row.ttl['data-saved-type'], addEventListener: (name, callback) => { listeners[name] = callback; },
        closest: selector => { assert.equal(selector, 'tr'); return {querySelector: selector => selector === '[data-saved-ttl]' ? ttl : (selector === 'input[name$="[mx_pref]"]' ? priority : null)}; }};
    return {ttl, priority, select, listeners};
});
assert.deepEqual(rows.map(row => row.ttl.dataset.savedTtl), ['300', '3600'], 'Saved rows render the TTL they were read with');
const document = {getElementById: () => null, addEventListener() {}, createElement: () => ({}),
    querySelectorAll: selector => selector === '.domains-record-form select[name$="[type]"]' ? rows.map(row => row.select) : []};
vm.runInNewContext(script, {document});
for (const row of rows) {
    const saved = row.ttl.dataset.savedTtl;
    assert.equal(row.ttl.textContent, saved, 'An unchanged type shows the saved TTL');
    assert.equal(row.priority.disabled, true, 'MX priority stays off for a non-MX type');
    row.select.value = 'MX'; row.listeners.change();
    assert.equal(row.ttl.textContent, 'Registrar default', 'A changed type says it gets the registrar default');
    assert.equal(row.priority.disabled, false, 'MX priority still follows the type');
    row.select.value = row.ttl.dataset.savedType; row.listeners.change();
    assert.equal(row.ttl.textContent, saved, 'Switching back to the saved type restores the saved TTL');
}
console.log('A changed record type shows the registrar default TTL, and the saved type restores the saved TTL.');

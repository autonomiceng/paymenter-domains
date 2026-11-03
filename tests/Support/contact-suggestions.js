// MIT, Copyright (c) 2026 Autonomic Engineering.
// Exercise the rendered contact fields through the real country-change handler.
const assert = require('node:assert/strict');
const vm = require('node:vm');
const {readFileSync} = require('node:fs');
const {contacts, script} = JSON.parse(readFileSync(0, 'utf8'));
const expected = {
    US: 'AL AK AZ AR CA CO CT DE DC FL GA HI ID IL IN IA KS KY LA ME MD MA MI MN MS MO MT NE NV NH NJ NM NY NC ND OH OK OR PA RI SC SD TN TX UT VT VA WA WV WI WY AS GU MP PR VI'.split(' '),
    CA: 'AB BC MB NB NL NS NT NU ON PE QC SK YT'.split(' '),
};
assert.equal(contacts.length, 10, 'Render every country with optional and always-required state fields');
for (const rendered of contacts) {
    const label = `${rendered.country || 'blank'}/${rendered.stateRequired}`;
    assert.deepEqual(rendered.options, expected[rendered.country] || [], `${label}: initial Blade suggestions match country`);
    const listeners = {};
    const country = {value: rendered.country, addEventListener: (name, callback) => { listeners[name] = callback; }};
    const state = {value: rendered.state, required: rendered.required};
    const list = {
        dataset: {stateCodes: rendered.codes},
        options: rendered.options.map(value => ({value})),
        replaceChildren(...options) { this.options = options; },
    };
    const group = {
        dataset: {stateRequired: rendered.stateRequired ? '1' : '0'},
        querySelector: selector => selector === '[name$="[country]"]' ? country
            : selector === '[name$="[state_province]"]' ? state
            : selector === 'datalist[data-state-codes]' ? list : null,
    };
    const document = {
        getElementById: () => null,
        querySelectorAll: selector => selector === '[data-contact-fields]' ? [group] : [],
        addEventListener() {},
        createElement: () => ({}),
    };
    vm.runInNewContext(script, {document});
    assert.equal(typeof listeners.change, 'function', 'Real script registers the country-change handler');
    const check = () => {
        assert.deepEqual(Array.from(list.options, option => option.value), expected[country.value] || [], `${label}: suggestions follow ${country.value}`);
        assert.equal(state.value, 'Retained state', 'Changing country preserves entered state');
        assert.equal(state.required, rendered.stateRequired || ['US', 'CA', 'AU'].includes(country.value), 'State requirement stays unchanged');
    };
    check();
    for (const next of ['CA', 'US', 'GB', 'AU', '', 'CA']) {
        country.value = next;
        listeners.change();
        check();
    }
}
console.log('Contact suggestions follow the selected country on render and change; state values and requirements are preserved.');

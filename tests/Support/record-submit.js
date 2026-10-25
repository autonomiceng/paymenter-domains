// MIT, Copyright (c) 2026 Autonomic Engineering.
// Run the rendered client script against the form event boundary, using rendered submitters and rows.
const assert = require('node:assert/strict');
const vm = require('node:vm');
const {readFileSync} = require('node:fs');
const {forms, script} = JSON.parse(readFileSync(0, 'utf8'));
for (const rendered of forms) {
    assert.equal(rendered.rows.length, 2, 'Two existing records exercise deletion of either row');
    const rows = rendered.rows.map(row => ({
        dataset: {record: row.attrs['data-record']},
        mark: null,
        querySelector() { return this.mark; },
        querySelectorAll: () => row.fields.filter(field => field.type !== 'hidden' && !('disabled' in field)).map(field => ({value: field.value || 'tcp'})),
    }));
    let opened = 0;
    const dialog = {showModal() { opened++; }, querySelector: () => null, addEventListener() {}};
    let submit;
    const form = {
        dataset: {confirmWhen: rendered.attrs['data-confirm-when'], current: rendered.attrs['data-current']},
        elements: {},
        querySelector: selector => selector === '[data-confirm-dialog]' ? dialog : null,
        querySelectorAll: selector => ['tr[data-record]', 'tbody tr'].includes(selector) ? rows : [],
        addEventListener: (name, callback) => { if (name === 'submit') submit = callback; },
    };
    const marks = [];
    const document = {
        getElementById: () => null,
        querySelectorAll: selector => selector === 'form[data-confirm]' ? [form] : [],
        addEventListener() {},
        createElement: () => ({}),
    };
    vm.runInNewContext(script, {document});
    assert.equal(typeof submit, 'function', 'Real script registers its submit handler');
    const button = attrs => ({
        ...attrs,
        matches: selector => selector === '[data-delete-record]' && 'data-delete-record' in attrs,
        after: mark => {
            marks.push(mark);
            const index = rendered.buttons.filter(item => 'data-delete-record' in item).findIndex(item => item.name === attrs.name);
            assert.ok(index >= 0, 'A deletion marker must belong to a rendered Delete button');
            rows[index].mark = mark;
        },
    });
    const defaults = rendered.buttons.filter(attrs => !('disabled' in attrs));
    const defaultSave = button(defaults[0]);
    const event = submitter => ({submitter, prevented: false, preventDefault() { this.prevented = true; }});
    // Browser implicit submission selects the first enabled submit control in tree order.
    for (let cycle = 0; cycle < 2; cycle++) {
        const enter = event(defaultSave);
        submit(enter);
        assert.equal(opened, 0, 'Enter with unchanged DNS/SRV rows never opens a delete dialog');
        assert.equal(enter.prevented, false, 'Implicit Save continues normally');
        assert.equal(marks.length, 0, 'Implicit Save creates no deletion marker');
    }
    const deletes = rendered.buttons.filter(attrs => 'data-delete-record' in attrs);
    assert.equal(deletes.length, 2, 'Both explicit row Delete controls are available');
    for (const [index, attrs] of deletes.entries()) {
        const clicked = button(attrs);
        const remove = event(clicked);
        submit(remove);
        assert.equal(marks.at(-1).name, attrs.name, 'Explicit Delete marks only its selected row');
        assert.equal(marks.at(-1).value, '1', 'Explicit Delete retains its marker value');
        assert.equal(opened, index + 1, 'Explicit Delete opens its confirmation dialog');
        assert.equal(remove.prevented, true, 'Explicit Delete waits for confirmation');
        assert.equal(clicked.hidden, true, 'Selected Delete is hidden until confirmation or cancel');
    }
}
console.log('DNS and SRV: implicit Save avoids delete dialogs; explicit Delete marks its own row.');

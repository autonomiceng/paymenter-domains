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
    let escape, cancelClick;
    const cancelButton = {addEventListener(name, callback) { if (name === 'click') cancelClick = callback; }};
    const dialog = {
        open: false,
        showModal() { opened++; this.open = true; },
        close() { this.open = false; },
        querySelector: selector => selector === '[data-confirm-cancel]' ? cancelButton : null,
        addEventListener(name, callback) { if (name === 'cancel') escape = callback; },
    };
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
        after(mark) {
            marks.push(mark);
            const index = rendered.buttons.filter(item => 'data-delete-record' in item).findIndex(item => item.name === attrs.name);
            assert.ok(index >= 0, 'A deletion marker must belong to a rendered Delete button');
            assert.equal(rows[index].mark, null, 'Canceled markers must be removed before another Delete');
            rows[index].mark = mark;
            mark.previousElementSibling = this;
            mark.remove = () => {
                assert.equal(rows[index].mark, mark, 'Remove clears its owning row');
                rows[index].mark = null;
                marks.splice(marks.indexOf(mark), 1);
            };
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
    assert.equal(typeof escape, 'function', 'Real script registers Esc cancellation');
    assert.equal(typeof cancelClick, 'function', 'Real script registers the Cancel button');
    let expectedOpened = 0;
    for (const cancel of ['Esc', 'Cancel']) for (const [index, attrs] of deletes.entries()) {
        const clicked = button(attrs);
        const remove = event(clicked);
        submit(remove);
        assert.equal(rows.filter(row => row.mark).length, 1, 'Exactly one active deletion marker');
        rows.forEach((row, rowIndex) => assert.equal(!!row.mark, rowIndex === index, 'Only the selected row has a deletion marker'));
        assert.equal(marks.at(-1).name, attrs.name, 'Explicit Delete marks only its selected row');
        assert.equal(marks.at(-1).value, '1', 'Explicit Delete retains its marker value');
        assert.equal(opened, ++expectedOpened, 'Explicit Delete opens its confirmation dialog');
        assert.equal(remove.prevented, true, 'Explicit Delete waits for confirmation');
        assert.equal(clicked.hidden, true, 'Selected Delete is hidden until confirmation or cancel');
        if (cancel === 'Esc') { escape(); dialog.open = false; } else { cancelClick(); }
        assert.equal(marks.length, 0, cancel + ' removes every active marker');
        rows.forEach(row => assert.equal(row.mark, null, cancel + ' clears every row'));
        assert.equal(clicked.hidden, false, cancel + ' restores the selected Delete button');
        assert.equal(dialog.open, false, cancel + ' closes the confirmation dialog');
        const save = event(defaultSave);
        submit(save);
        assert.equal(save.prevented, false, 'Save after cancel submits without confirmation');
        assert.equal(opened, expectedOpened, 'Save after cancel opens no new dialog');
    }
}
console.log('DNS and SRV: implicit Save avoids delete dialogs; explicit Delete, Esc and Cancel preserve only active row markers.');

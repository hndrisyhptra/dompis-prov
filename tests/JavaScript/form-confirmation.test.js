import test from 'node:test';
import assert from 'node:assert/strict';
import { setupFormConfirmation } from '../../resources/js/form-confirmation.js';

function fixture() {
    const root = new EventTarget();
    const fields = Object.fromEntries(['title', 'description', 'context', 'yes', 'no'].map(name => [name, Object.assign(new EventTarget(), { textContent: '', classList: { toggle() {} } })]));
    const dialog = Object.assign(new EventTarget(), {
        open: false,
        querySelector(selector) { return fields[selector.slice('[data-confirm-'.length, -1)]; },
        showModal() { this.open = true; },
        close() { this.open = false; this.dispatchEvent(new Event('close')); },
    });
    root.querySelector = () => dialog;
    let fallbackResult = false;
    setupFormConfirmation(root, () => fallbackResult);
    function submit(form, submitter) {
        const event = new Event('submit', { cancelable: true });
        Object.defineProperties(event, { target: { value: form }, submitter: { value: submitter } });
        root.dispatchEvent(event);
        if (!event.defaultPrevented) form.sent.push(submitter);
        return event;
    }
    function form(options = {}) {
        const form = {
            method: 'post', dataset: {}, isConnected: true, valid: true, sent: [],
            closest() { return options.outside ? null : {}; },
            hasAttribute() { return options.skip || false; },
            querySelector() { return options.override ? { value: options.override } : null; },
            checkValidity() { return this.valid; },
            reportValidity() { this.reported = true; },
            requestSubmit(button) { if (this.valid) submit(this, button); },
            ...options,
        };
        return form;
    }
    function button(form) { return { form, isConnected: true, disabled: false, textContent: 'Simpan perubahan', name: 'intent', value: 'save' }; }
    return { root, dialog, fields, submit, form, button, fallback: value => { fallbackResult = value; } };
}

test('save is held until Yes, then submits exactly once with original submitter and live values', () => {
    const f = fixture(); const form = f.form(); const button = f.button(form);
    form.values = { csrf: 'test-token', revision: '7', notes: 'Detail', file: { name: 'synthetic.xlsx' } };
    const original = form.values;
    assert.equal(f.submit(form, button).defaultPrevented, true);
    assert.equal(form.sent.length, 0);
    assert.equal(f.dialog.open, true);
    assert.equal(form.dataset.submitting, undefined);
    f.fields.yes.dispatchEvent(new Event('click'));
    f.fields.yes.dispatchEvent(new Event('click'));
    assert.deepEqual(form.sent, [button]);
    assert.equal(form.values, original);
    assert.equal(button.value, 'save');
    assert.equal(f.dialog.open, false);
});

test('No and Escape never send a request and a later action must be confirmed again', () => {
    const f = fixture(); const form = f.form(); const button = f.button(form);
    f.submit(form, button); f.fields.no.dispatchEvent(new Event('click'));
    f.fields.yes.dispatchEvent(new Event('click'));
    assert.equal(form.sent.length, 0);
    f.submit(form, button); f.dialog.dispatchEvent(new Event('cancel')); f.dialog.close();
    f.fields.yes.dispatchEvent(new Event('click'));
    assert.equal(form.sent.length, 0);
    f.submit(form, button);
    assert.equal(f.dialog.open, true);
    assert.equal(form.sent.length, 0);
});

test('delete has distinct wording and confirmation cannot submit another form', () => {
    const f = fixture(); const first = f.form({ override: 'DELETE' }); const other = f.form();
    f.submit(first, f.button(first));
    assert.equal(f.fields.yes.textContent, 'Ya, Hapus');
    assert.match(f.fields.description.textContent, /menghapus/);
    f.submit(other, f.button(other));
    f.fields.yes.dispatchEvent(new Event('click'));
    assert.equal(first.sent.length, 1);
    assert.equal(other.sent.length, 0);
});

test('invalid form, disconnected form or disabled submitter does not save', () => {
    const f = fixture(); const invalid = f.form({ valid: false });
    f.submit(invalid, f.button(invalid));
    assert.equal(invalid.reported, true);
    assert.equal(f.dialog.open, false);
    const removed = f.form(); f.submit(removed, f.button(removed)); removed.isConnected = false;
    f.fields.yes.dispatchEvent(new Event('click'));
    assert.equal(removed.sent.length, 0);
    const disabled = f.form(); const button = f.button(disabled); f.submit(disabled, button); button.disabled = true;
    f.fields.yes.dispatchEvent(new Event('click'));
    assert.equal(disabled.sent.length, 0);
});

test('validation is repeated after confirmation and approval is not retained after failed validation', () => {
    const f = fixture(); const form = f.form(); const button = f.button(form);
    f.submit(form, button); form.valid = false; f.fields.yes.dispatchEvent(new Event('click'));
    assert.equal(form.sent.length, 0);
    form.valid = true; f.submit(form, button);
    assert.equal(f.dialog.open, true);
    assert.equal(form.sent.length, 0);
});

test('filters, guest/outside forms and existing logout confirmation are not intercepted', () => {
    const f = fixture();
    for (const options of [{ method: 'get' }, { outside: true }, { skip: true }]) {
        const form = f.form(options);
        assert.equal(f.submit(form, f.button(form)).defaultPrevented, false);
        assert.equal(f.dialog.open, false);
    }
});

test('already submitting form is blocked and unsupported dialog has a safe confirmation fallback', () => {
    const f = fixture(); const busy = f.form({ dataset: { submitting: '1' } });
    f.submit(busy, f.button(busy)); assert.equal(busy.sent.length, 0);
    f.dialog.showModal = undefined;
    const form = f.form(); f.submit(form, f.button(form)); assert.equal(form.sent.length, 0);
    f.fallback(true); f.submit(form, f.button(form)); assert.equal(form.sent.length, 1);
});

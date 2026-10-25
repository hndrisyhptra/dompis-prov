import test from 'node:test';
import assert from 'node:assert/strict';
import { setupJtBoq } from '../../resources/js/jt-boq.js';
function fixture() {
    const form = new EventTarget(), category = Object.assign(new EventTarget(), { value: 'SURVEY' });
    const save = {}, next = { removeAttribute() { delete this.name; } };
    const buttons = [{ name: 'boq_next', value: 'upload' }, { name: 'boq_next', value: 'skip' }];
    const options = { closest: () => form, querySelectorAll: () => buttons };
    form.querySelector = key => key.includes('category') ? category : key.includes('normal') ? save : next;
    setupJtBoq({ querySelector: () => options });
    return { form, category, save, next, options, buttons, select(value) { category.value = value; category.dispatchEvent(new Event('change')); }, submit(button, cancelled = false) {
        const event = new Event('submit', { cancelable: true });
        Object.defineProperty(event, 'submitter', { value: button });
        if (cancelled) event.preventDefault();
        form.dispatchEvent(event);
    } };
}
test('JT enables upload/skip actions and switching away removes hidden intent and restores ordinary save', () => {
    const f = fixture();
    assert.equal(f.options.hidden, true);
    assert.equal(f.buttons[0].disabled, true);
    f.select('JT');
    assert.equal(f.options.hidden, false);
    assert.equal(f.save.disabled, true);
    assert.equal(f.buttons[0].disabled, false);
    f.next.name = 'boq_next';
    f.select('INSTALASI');
    assert.equal(f.next.name, undefined);
    assert.equal(f.save.disabled, false);
});
test('approved upload/skip intent survives loading-disabled submitters but cancelled confirmations do not submit intent', () => {
    const f = fixture();
    f.select('JT');
    f.submit(f.buttons[0], true);
    assert.equal(f.next.name, undefined);
    f.buttons[0].disabled = true;
    f.submit(f.buttons[0]);
    assert.equal(f.next.name, 'boq_next');
    assert.equal(f.next.value, 'upload');
    f.submit(f.buttons[1]);
    assert.equal(f.next.value, 'skip');
});

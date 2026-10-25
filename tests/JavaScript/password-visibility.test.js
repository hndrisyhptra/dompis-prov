import test from 'node:test';
import assert from 'node:assert/strict';
import { setupPasswordVisibility } from '../../resources/js/password-visibility.js';

test('password toggles preserve input value, update accessible state, and act independently', () => {
    const fields = { password: { type: 'password', value: 'secret-value' }, confirmation: { type: 'password', value: 'another-value' } };
    const buttons = Object.keys(fields).map(id => {
        const icons = { '[data-password-show]': { hidden: false }, '[data-password-hide]': { hidden: true } };
        return { hidden: true, attrs: { 'aria-controls': id }, getAttribute(key) { return this.attrs[key]; }, setAttribute(key, value) { this.attrs[key] = value; }, addEventListener(_name, handler) { this.click = handler; }, querySelector(key) { return icons[key]; } };
    });
    setupPasswordVisibility({ querySelectorAll: () => buttons, getElementById: id => fields[id] });
    assert.equal(buttons[0].hidden, false);
    buttons[0].click();
    assert.equal(fields.password.type, 'text');
    assert.equal(fields.password.value, 'secret-value');
    assert.equal(fields.confirmation.type, 'password');
    assert.equal(buttons[0].attrs['aria-label'], 'Sembunyikan kata sandi');
    assert.equal(buttons[0].attrs['aria-pressed'], 'true');
    buttons[0].click();
    assert.equal(fields.password.type, 'password');
    assert.equal(fields.password.value, 'secret-value');
    assert.equal(buttons[0].attrs['aria-pressed'], 'false');
});

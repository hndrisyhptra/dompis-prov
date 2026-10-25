import test from 'node:test';
import assert from 'node:assert/strict';
import { setupMaterialTemplate } from '../../resources/js/material-template.js';

function fixture(clipboard) {
    const field = { value: '/material\nNO TQ/SC/ID:', focus() { this.focused = true; }, select() { this.selected = true; } };
    const status = {};
    const button = { addEventListener(_, handler) { this.click = handler; } };
    setupMaterialTemplate({ getElementById: () => field, querySelector: key => key === '[data-copy-material]' ? button : status }, clipboard);
    return { field, status, button };
}

test('material template copies exact content and reports clipboard success', async () => {
    let copied;
    const { field, status, button } = fixture({ writeText: async value => { copied = value; } });
    await button.click();
    assert.equal(copied, field.value);
    assert.match(status.textContent, /Template disalin/);
});

test('clipboard rejection selects actual template for manual copy', async () => {
    const { field, status, button } = fixture({ writeText: async () => { throw new Error('denied'); } });
    await button.click();
    assert.equal(field.focused, true);
    assert.equal(field.selected, true);
    assert.match(status.textContent, /Salin otomatis belum tersedia/);
});

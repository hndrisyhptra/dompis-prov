import test from 'node:test';
import assert from 'node:assert/strict';
import { setupExcelDownloads } from '../../resources/js/excel-downloads.js';

function fixture() {
    const calls = [];
    const status = { textContent: '', classList: { add() {}, remove() {} } };
    const link = { href: 'https://olo.test/work-orders/export?branch=4&month=2026-10', innerHTML: 'Download Excel', attrs: {}, getAttribute(key) { return this.attrs[key]; }, setAttribute(key, value) { this.attrs[key] = value; }, removeAttribute(key) { delete this.attrs[key]; }, closest() { return { querySelector: () => status }; } };
    const downloads = [];
    const revoked = [];
    const timers = [];
    const root = { addEventListener(_, handler) { this.handler = handler; }, body: { append() {} }, createElement() { return { click() { downloads.push({ href: this.href, filename: this.download }); }, remove() {} }; } };
    const fetcher = (url, options) => new Promise((resolve, reject) => calls.push({ url, options, resolve, reject }));
    const browser = { location: { href: 'https://olo.test/dashboard' }, URL: { createObjectURL: () => 'blob:test-export', revokeObjectURL: url => revoked.push(url) }, setTimeout: callback => timers.push(callback) };
    setupExcelDownloads(root, fetcher, browser);
    function click(extras = {}) {
        const event = { target: { closest: () => link }, button: 0, preventDefault() { this.defaultPrevented = true; }, ...extras };
        return { event, done: root.handler(event) };
    }
    return { link, status, calls, downloads, revoked, timers, click };
}

test('Excel download preserves filters, prevents duplicate clicks, restores control and cleans up the file URL', async () => {
    const f = fixture();
    const first = f.click();
    assert.equal(first.event.defaultPrevented, true);
    assert.equal(f.link.attrs['aria-busy'], 'true');
    assert.match(f.link.innerHTML, /Menyiapkan/);
    await f.click().done;
    assert.equal(f.calls.length, 1);
    assert.equal(new URL(f.calls[0].url).searchParams.get('branch'), '4');
    assert.equal(f.calls[0].options.credentials, 'same-origin');
    f.calls[0].resolve({ ok: true, headers: { get: name => name === 'Content-Type' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'attachment; filename="dompis-work-orders-20261026.xlsx"' }, blob: async () => ({}) });
    await first.done;
    assert.deepEqual(f.downloads, [{ href: 'blob:test-export', filename: 'dompis-work-orders-20261026.xlsx' }]);
    assert.equal(f.link.innerHTML, 'Download Excel');
    assert.equal(f.link.attrs['aria-busy'], undefined);
    assert.match(f.status.textContent, /siap diunduh/);
    f.timers[0]();
    assert.deepEqual(f.revoked, ['blob:test-export']);
});

test('expired sessions, rate limits and network errors show actionable failure without downloading an HTML error', async () => {
    for (const kind of ['html', 'rate', 'network']) {
        const f = fixture();
        const first = f.click();
        if (kind === 'network') f.calls[0].reject(new Error('offline'));
        else f.calls[0].resolve({ ok: kind === 'html', status: kind === 'rate' ? 429 : 200, headers: { get: () => 'text/html' } });
        await first.done;
        assert.equal(f.downloads.length, 0);
        assert.equal(f.link.attrs['aria-disabled'], undefined);
        assert.match(f.status.textContent, kind === 'rate' ? /Batas unduhan/ : /Unduhan belum berhasil/);
        const retry = f.click();
        f.calls[1].reject(new Error('offline'));
        await retry.done;
        assert.equal(f.calls.length, 2);
    }
});

test('modified clicks and external URLs retain normal browser behavior', async () => {
    for (const extra of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { altKey: true }, { button: 1 }]) {
        const f = fixture();
        const click = f.click(extra);
        await click.done;
        assert.equal(click.event.defaultPrevented, undefined);
        assert.equal(f.calls.length, 0);
    }
    const f = fixture();
    f.link.href = 'https://other.test/export';
    await f.click().done;
    assert.equal(f.calls.length, 0);
});

test('export validation explains an oversized database value without downloading a truncated workbook', async () => {
    const f = fixture();
    const click = f.click();
    f.calls[0].resolve({ ok: false, status: 422, headers: { get: () => 'application/json' }, json: async () => ({ errors: { export: ['Kolom source_bima_fields melebihi batas Excel; file tidak dibuat.'] } }) });
    await click.done;
    assert.equal(f.downloads.length, 0);
    assert.match(f.status.textContent, /source_bima_fields.*batas Excel/);
    assert.equal(f.link.attrs['aria-busy'], undefined);
});

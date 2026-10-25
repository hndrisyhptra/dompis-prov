import test from 'node:test';
import assert from 'node:assert/strict';
import { setupDashboardTables } from '../../resources/js/dashboard-tables.js';

function fixture(supported = true) {
    const root = new EventTarget();
    const content = { innerHTML: '', attributes: {}, setAttribute(k, v) { this.attributes[k] = v; }, querySelector() { return null; } };
    const title = { textContent: '' };
    const fullList = { href: '' };
    const dialog = Object.assign(new EventTarget(), {
        open: false,
        showModal: supported ? function () { this.open = true; } : undefined,
        close() { this.open = false; this.dispatchEvent(new Event('close')); },
        querySelector(s) { return s.includes('content') ? content : s.includes('title') ? title : fullList; },
    });
    const bodies = [{ hidden: false }, { hidden: false }];
    const buttons = bodies.map((_, i) => Object.assign(new EventTarget(), { attributes: { 'aria-controls': `region-${i}` }, getAttribute(k) { return this.attributes[k]; }, setAttribute(k, v) { this.attributes[k] = v; } }));
    root.querySelectorAll = () => buttons;
    root.querySelector = () => dialog;
    root.getElementById = id => bodies[Number(id.split('-')[1])];
    const requests = [];
    const fetcher = (url, options) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject }));
    setupDashboardTables(root, fetcher, 'https://olo.test/dashboard');
    function link(href, kind = 'a[data-dashboard-orders]', label = 'Survey') {
        const node = { href, dataset: { modalTitle: label }, isConnected: true, focused: 0, focus() { this.focused++; } };
        node.closest = s => s === kind ? node : null;
        return node;
    }
    function click(surface, target, extras = {}) {
        const event = new Event('click', { cancelable: true });
        Object.defineProperties(event, { target: { value: target }, button: { value: 0 }, ...Object.fromEntries(Object.entries(extras).map(([k, v]) => [k, { value: v }])) });
        surface.dispatchEvent(event);
        return event;
    }
    return { root, content, title, fullList, dialog, buttons, bodies, requests, click, link };
}
const settle = () => new Promise(resolve => setImmediate(resolve));
test('SVG line chart points open the same scoped dialog with SVGAnimatedString href', async () => {
    const f = fixture();
    const href = 'https://olo.test/work-orders?order_month=2026-07&queue=complete&branch=4';
    const point = f.link({ baseVal: href }, undefined, 'Juli 2026 · Sudah PS');
    assert.equal(f.click(f.root, point).defaultPrevented, true);
    assert.equal(f.fullList.href, href);
    assert.equal(new URL(f.requests[0].url).searchParams.get('order_month'), '2026-07');
    assert.equal(f.title.textContent, 'Juli 2026 · Sudah PS');
    respond(f.requests[0], '<p>Order PS bulan Juli</p>');
    await settle();
    assert.match(f.content.innerHTML, /Order PS bulan Juli/);
});
function respond(request, html, marker = 'orders') {
    request.resolve({ ok: true, headers: { get() { return marker; } }, async text() { return html; } });
}

test('Region summaries start collapsed and each toggle changes only its own rows and aria state', () => {
    const f = fixture();
    assert.deepEqual(f.bodies.map(b => b.hidden), [true, true]);
    f.buttons[0].dispatchEvent(new Event('click'));
    assert.deepEqual(f.bodies.map(b => b.hidden), [false, true]);
    assert.equal(f.buttons[0].attributes['aria-expanded'], 'true');
    f.buttons[0].dispatchEvent(new Event('click'));
    assert.equal(f.buttons[0].attributes['aria-expanded'], 'false');
});

test('a number opens a loading modal and fetches a scoped GET while retaining the full Work Order link', async () => {
    const f = fixture();
    const href = 'https://olo.test/work-orders?branch=4&bucket=instalasi&jenis_pekerjaan=all';
    const link = f.link(href, undefined, 'Branch Uji · Instalasi');
    assert.equal(f.click(f.root, link).defaultPrevented, true);
    assert.equal(f.dialog.open, true);
    assert.equal(f.title.textContent, 'Branch Uji · Instalasi');
    assert.equal(f.content.attributes['aria-busy'], 'true');
    assert.match(f.content.innerHTML, /Memuat daftar order/);
    const url = new URL(f.requests[0].url);
    assert.equal(url.searchParams.get('branch'), '4');
    assert.equal(url.searchParams.get('bucket'), 'instalasi');
    assert.equal(url.searchParams.get('modal'), '1');
    assert.equal(f.fullList.href, href);
    assert.equal(f.requests[0].options.credentials, 'same-origin');
    respond(f.requests[0], '<table>Order yang sesuai</table>');
    await settle();
    assert.match(f.content.innerHTML, /Order yang sesuai/);
    assert.equal(f.content.attributes['aria-busy'], 'false');
});

test('failed or expired-session responses show an error with retry and empty results render server empty state', async () => {
    const f = fixture();
    f.click(f.root, f.link('https://olo.test/work-orders?queue=today'));
    respond(f.requests[0], '<html>login page</html>', null);
    await settle();
    assert.match(f.content.innerHTML, /Daftar order belum dapat dimuat/);
    assert.doesNotMatch(f.content.innerHTML, /login page/);
    const retry = { closest: s => s === '[data-order-retry]' ? retry : null };
    f.click(f.dialog, retry);
    assert.equal(f.requests.length, 2);
    respond(f.requests[1], '<h3>Belum ada order untuk pilihan ini</h3>');
    await settle();
    assert.match(f.content.innerHTML, /Belum ada order untuk pilihan ini/);
});

test('late responses cannot replace a newer selection or reopen a closed modal and focus returns to trigger', async () => {
    const f = fixture();
    const first = f.link('https://olo.test/work-orders?bucket=survey');
    const second = f.link('https://olo.test/work-orders?bucket=instalasi');
    f.click(f.root, first);
    f.click(f.root, second);
    assert.equal(f.requests[0].options.signal.aborted, true);
    respond(f.requests[1], '<p>Newest selection</p>');
    await settle();
    respond(f.requests[0], '<p>Stale selection</p>');
    await settle();
    assert.equal(f.content.innerHTML, '<p>Newest selection</p>');
    f.click(f.root, second);
    f.dialog.close();
    assert.equal(f.requests[2].options.signal.aborted, true);
    assert.equal(second.focused, 1);
    respond(f.requests[2], '<p>Late response</p>');
    await settle();
    assert.equal(f.dialog.open, false);
    assert.equal(f.content.innerHTML, '');
});

test('pagination stays in the modal with original filters and Detail links retain normal navigation', async () => {
    const f = fixture();
    f.click(f.root, f.link('https://olo.test/work-orders?owner_group=PMD&region=2'));
    respond(f.requests[0], '<p>Page one</p>');
    await settle();
    const page = f.link('https://olo.test/work-orders?owner_group=PMD&region=2&page=2&modal=1', 'a[data-order-page]');
    assert.equal(f.click(f.dialog, page).defaultPrevented, true);
    assert.equal(new URL(f.requests[1].url).searchParams.get('owner_group'), 'PMD');
    assert.equal(new URL(f.requests[1].url).searchParams.get('page'), '2');
    const detail = f.link('https://olo.test/work-orders/7', 'a');
    assert.equal(f.click(f.dialog, detail).defaultPrevented, false);
});

test('modified clicks, external URLs and unsupported dialogs preserve normal links without fetching', () => {
    for (const extras of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { altKey: true }, { button: 1 }]) {
        const f = fixture();
        assert.equal(f.click(f.root, f.link('https://olo.test/work-orders'), extras).defaultPrevented, false);
        assert.equal(f.requests.length, 0);
    }
    const f = fixture();
    assert.equal(f.click(f.root, f.link('https://external.test/work-orders')).defaultPrevented, false);
    const unsupported = fixture(false);
    assert.equal(unsupported.click(unsupported.root, unsupported.link('https://olo.test/work-orders')).defaultPrevented, false);
    assert.equal(unsupported.requests.length, 0);
});

import test from 'node:test';
import assert from 'node:assert/strict';
import { setupSyncPolling } from '../../resources/js/sync-polling.js';

function fixture(path = '/sync/jobs/28') {
    const state = { textContent: 'running' }, error = { textContent: '' };
    const retry = { hidden: true, disabled: false, addEventListener(_, fn) { this.click = fn; } };
    const poll = { dataset: { syncPoll: path, syncJob: '28' }, querySelector: key => ({ '[data-poll-state]': state, '[data-poll-error]': error, '[data-poll-retry]': retry })[key] };
    const timers = new Map(), calls = [];
    let serial = 0, reloads = 0;
    const browser = { location: { href: 'https://wibsy.test/sync/jobs/28', reload() { reloads++; } },
        setTimeout(fn, ms) { timers.set(++serial, { fn, ms }); return serial; }, clearTimeout(id) { timers.delete(id); }, addEventListener(_, fn) { this.pagehide = fn; } };
    const fetcher = (url, options) => new Promise((resolve, reject) => {
        calls.push({ url, options, resolve, reject });
        options.signal.addEventListener('abort', () => reject(Object.assign(new Error('timeout'), { name: 'AbortError' })), { once: true });
    });
    const controller = setupSyncPolling({ querySelector: () => poll }, fetcher, browser);
    const response = (body = { id_sync_jobs: 28, state: 'running' }, status = 200, type = 'application/json', extras = {}) => ({ ok: status >= 200 && status < 300, status, headers: { get: key => key === 'Retry-After' ? '60' : type }, json: async () => body, ...extras });
    return { state, error, retry, timers, calls, browser, controller, response, get reloads() { return reloads; } };
}

test('poll uses HTTPS current origin, credentials and no cache, reloads only for matching terminal job', async () => {
    const f = fixture();
    let done = f.controller.refresh();
    assert.equal(f.calls[0].url, 'https://wibsy.test/sync/jobs/28');
    assert.equal(f.calls[0].options.cache, 'no-store');
    assert.equal(f.calls[0].options.credentials, 'same-origin');
    f.calls[0].resolve(f.response());
    await done;
    assert.equal(f.state.textContent, 'running');
    assert.equal(f.retry.hidden, true);
    done = f.controller.refresh();
    f.calls[1].resolve(f.response({ id_sync_jobs: 28, state: 'preview_ready' }));
    await done;
    assert.equal(f.reloads, 1);
    assert.equal(f.timers.size, 0);
});

test('transient failures back off, continue beyond ten failures, and recover without duplicate requests', async () => {
    const f = fixture();
    for (let i = 0; i < 12; i++) {
        const done = f.controller.refresh();
        await f.controller.refresh();
        assert.equal(f.calls.length, i + 1);
        f.calls[i].reject(new Error('offline'));
        await done;
        assert.equal(f.retry.hidden, false);
        assert.ok([...f.timers.values()].some(t => t.ms >= 6000 && t.ms <= 30000));
    }
    const done = f.controller.refresh();
    f.calls[12].resolve(f.response());
    await done;
    assert.equal(f.error.textContent, '');
    assert.equal(f.retry.hidden, true);
    assert.ok([...f.timers.values()].some(t => t.ms === 3000));
});

test('session, forbidden, HTML, wrong job and foreign URL stop automatic polling with actionable feedback', async () => {
    for (const kind of ['session', 'forbidden', 'html', 'redirect', 'wrong-job', 'foreign']) {
        const f = fixture(kind === 'foreign' ? 'http://old-backend.test/sync/jobs/28' : undefined);
        const done = f.controller.refresh();
        if (kind !== 'foreign') {
            const response = kind === 'session' ? f.response({}, 401) : kind === 'forbidden' ? f.response({}, 403) : kind === 'html' ? f.response({}, 200, 'text/html') : kind === 'redirect' ? f.response({}, 200, 'text/html', { redirected: true }) : f.response({ id_sync_jobs: 29, state: 'preview_ready' });
            f.calls[0].resolve(response);
        }
        await done;
        assert.equal(f.reloads, 0);
        assert.equal(f.timers.size, 0);
        assert.equal(f.retry.hidden, false);
        assert.notEqual(f.error.textContent, '');
        if (kind === 'foreign') assert.equal(f.calls.length, 0);
        if (kind === 'forbidden') assert.match(f.error.textContent, /403/);
        if (kind === 'session') assert.match(f.error.textContent, /Sesi login/);
    }
});

test('manual retry resumes after denied access and preserves displayed worker state until server responds', async () => {
    const f = fixture();
    const done = f.controller.refresh();
    f.calls[0].resolve(f.response({}, 403));
    await done;
    f.retry.click();
    assert.equal(f.calls.length, 2);
    assert.equal(f.retry.disabled, true);
    assert.equal(f.state.textContent, 'running');
    f.calls[1].resolve(f.response({ id_sync_jobs: 28, state: 'success' }));
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(f.reloads, 1);
});

test('HTTP 500 and rate limits remain recoverable with HTTP code and Retry-After delay', async () => {
    for (const status of [500, 429]) {
        const f = fixture();
        const done = f.controller.refresh();
        f.calls[0].resolve(f.response({}, status));
        await done;
        assert.match(f.error.textContent, new RegExp(String(status)));
        assert.equal(f.state.textContent, 'running');
        assert.ok(f.timers.size > 0);
        if (status === 429) assert.ok([...f.timers.values()].some(t => t.ms === 60000));
    }
});

test('timeout aborts only the status request; pagehide aborts and prevents retry scheduling', async () => {
    const f = fixture();
    let done = f.controller.refresh();
    [...f.timers.values()].find(t => t.ms === 20000).fn();
    await done;
    assert.match(f.error.textContent, /batas waktu/);
    assert.equal(f.state.textContent, 'running');
    done = f.controller.refresh();
    f.browser.pagehide();
    await done;
    assert.equal(f.timers.size, 0);
    assert.equal(f.reloads, 0);
});

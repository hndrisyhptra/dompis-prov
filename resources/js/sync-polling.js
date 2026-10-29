export function setupSyncPolling(root, fetcher = fetch, browser = window) {
    const poll = root.querySelector('[data-sync-poll]');
    if (!poll) return;
    const state = poll.querySelector('[data-poll-state]');
    const error = poll.querySelector('[data-poll-error]');
    const retry = poll.querySelector('[data-poll-retry]');
    let timer, controller, failures = 0, busy = false, stopped = false;
    const failure = (message, halt = false) => Object.assign(new Error(message), { feedback: message, halt });
    function schedule(delay) {
        browser.clearTimeout(timer);
        timer = browser.setTimeout(refresh, delay);
    }
    function stop() {
        stopped = true;
        browser.clearTimeout(timer);
        controller?.abort();
    }
    async function refresh() {
        if (busy || stopped) return;
        browser.clearTimeout(timer);
        busy = true;
        retry.disabled = true;
        controller = new AbortController();
        const timeout = browser.setTimeout(() => controller?.abort(), 20000);
        let minimumDelay = 0;
        try {
            const url = new URL(poll.dataset.syncPoll, browser.location.href);
            if (url.origin !== new URL(browser.location.href).origin) throw failure('Alamat pembaruan tidak sesuai situs ini. Muat ulang halaman setelah konfigurasi server diperiksa.', true);
            const response = await fetcher(url.href, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
            if ([401, 419].includes(response.status) || response.redirected) throw failure('Sesi login berakhir atau halaman dialihkan. Muat ulang halaman dan masuk kembali.', true);
            if (response.status === 403) throw failure('Akses pembaruan status ditolak (HTTP 403). Muat ulang halaman dan periksa akun login.', true);
            if (response.status === 404) throw failure('Riwayat sinkronisasi tidak ditemukan (HTTP 404). Kembali ke Sync Center.', true);
            if (response.status === 429) {
                minimumDelay = Math.min(120000, Math.max(30000, (Number(response.headers.get('Retry-After')) || 0) * 1000));
                throw failure('Pembaruan status dibatasi server (HTTP 429). Tunggu sebentar; pemeriksaan akan dicoba lagi.');
            }
            if (!response.ok) throw failure(`Server belum dapat memperbarui status (HTTP ${response.status}). Status proses belum dapat dipastikan; coba perbarui lagi.`);
            if (!response.headers.get('Content-Type')?.includes('application/json')) throw failure('Server mengirim halaman, bukan pembaruan status. Muat ulang halaman untuk memeriksa sesi login.', true);
            let result;
            try { result = await response.json(); } catch (_) { throw failure('Balasan status tidak dapat dibaca. Pemeriksaan akan dicoba lagi.'); }
            const states = ['queued', 'running', 'retrying', 'awaiting_mapping', 'preview_ready', 'success', 'partial_success', 'failed'];
            if (!states.includes(result?.state) || (poll.dataset.syncJob && String(result.id_sync_jobs) !== poll.dataset.syncJob)) throw failure('Balasan status tidak sesuai riwayat ini. Muat ulang halaman untuk memeriksa kembali.', true);
            if (!['queued', 'running', 'retrying'].includes(result.state)) {
                stopped = true;
                browser.location.reload();
                return;
            }
            state.textContent = result.state;
            error.textContent = result.error || '';
            retry.hidden = true;
            failures = 0;
        } catch (exception) {
            if (stopped) return;
            failures++;
            error.textContent = exception.feedback || (exception.name === 'AbortError' ? 'Pemeriksaan status melewati batas waktu. Status proses belum dapat dipastikan; coba perbarui lagi.' : 'Koneksi pembaruan status terputus. Status proses belum dapat dipastikan; pemeriksaan akan dicoba lagi.');
            retry.hidden = false;
            if (exception.halt) stopped = true;
        } finally {
            browser.clearTimeout(timeout);
            controller = null;
            busy = false;
            retry.disabled = false;
        }
        if (!stopped) schedule(Math.max(minimumDelay, Math.min(30000, 3000 * 2 ** Math.min(failures, 4))));
    }
    retry.addEventListener('click', () => { stopped = false; failures = 0; refresh(); });
    browser.addEventListener?.('pagehide', stop, { once: true });
    schedule(3000);
    return { refresh, stop };
}

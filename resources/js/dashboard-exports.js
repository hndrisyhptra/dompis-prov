export function setupDashboardExports(root, fetcher = fetch, browser = window) {
    root.addEventListener('click', async event => {
        if (event.defaultPrevented || event.button > 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest?.('a[data-dashboard-export]');
        if (!link) return;
        const url = new URL(link.href, browser.location.href);
        if (url.origin !== new URL(browser.location.href).origin) return;
        event.preventDefault();
        if (link.getAttribute('aria-busy') === 'true') return;
        const status = link.closest('.dashboard-export-control').querySelector('[data-export-status]');
        const original = link.innerHTML;
        link.setAttribute('aria-busy', 'true');
        link.setAttribute('aria-disabled', 'true');
        link.innerHTML = '<span class="spinner" aria-hidden="true"></span><span>Menyiapkan…</span>';
        status.textContent = '';
        status.classList.remove('danger');
        let objectUrl;
        try {
            const response = await fetcher(url.href, { credentials: 'same-origin', headers: { Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' } });
            if (!response.ok || !response.headers.get('Content-Type')?.includes('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')) {
                throw new Error(response.status === 429 ? 'limit' : 'download');
            }
            const blob = await response.blob();
            objectUrl = browser.URL.createObjectURL(blob);
            const filename = response.headers.get('Content-Disposition')?.match(/filename="?([A-Za-z0-9_-]+\.xlsx)"?/i)?.[1] || 'dashboard.xlsx';
            const download = root.createElement('a');
            download.href = objectUrl;
            download.download = filename;
            root.body.append(download);
            download.click();
            download.remove();
            status.textContent = 'File Excel siap diunduh.';
        } catch (error) {
            status.textContent = error.message === 'limit' ? 'Batas unduhan tercapai. Tunggu sebentar, lalu coba lagi.' : 'Unduhan belum berhasil. Periksa koneksi/sesi login, lalu klik Excel untuk mencoba lagi.';
            status.classList.add('danger');
        } finally {
            link.innerHTML = original;
            link.removeAttribute('aria-busy');
            link.removeAttribute('aria-disabled');
            if (objectUrl) browser.setTimeout(() => browser.URL.revokeObjectURL(objectUrl), 10000);
        }
    });
}

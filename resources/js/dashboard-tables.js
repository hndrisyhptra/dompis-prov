export function setupDashboardTables(root, fetcher = fetch, baseUrl = location.href) {
    root.querySelectorAll('[data-region-toggle]').forEach(button => {
        const body = root.getElementById(button.getAttribute('aria-controls'));
        if (!body) return;
        body.hidden = true;
        button.setAttribute('aria-expanded', 'false');
        button.addEventListener('click', () => {
            body.hidden = !body.hidden;
            button.setAttribute('aria-expanded', String(!body.hidden));
        });
    });

    const dialog = root.querySelector('#dashboard-order-dialog');
    if (!dialog || typeof dialog.showModal !== 'function') return;
    const content = dialog.querySelector('[data-order-dialog-content]');
    const title = dialog.querySelector('#dashboard-order-title');
    const fullList = dialog.querySelector('[data-order-full-list]');
    let controller;
    let sequence = 0;
    let trigger;
    let currentUrl;
    const origin = new URL(baseUrl).origin;

    async function load(url) {
        controller?.abort();
        const request = ++sequence;
        controller = new AbortController();
        currentUrl = url;
        content.setAttribute('aria-busy', 'true');
        content.innerHTML = '<div class="dashboard-dialog-state" role="status"><span class="spinner" aria-hidden="true"></span><p>Memuat daftar order…</p></div>';
        try {
            const response = await fetcher(url.href, { headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', signal: controller.signal });
            if (!response.ok || response.headers.get('X-Dashboard-Fragment') !== 'orders') throw new Error('Invalid response');
            const html = await response.text();
            if (request !== sequence || !dialog.open) return;
            content.innerHTML = html;
            const listUrl = content.querySelector('[data-order-list-url]')?.dataset.orderListUrl;
            if (listUrl && new URL(listUrl, baseUrl).origin === origin) fullList.href = listUrl;
        } catch (error) {
            if (error.name === 'AbortError' || request !== sequence || !dialog.open) return;
            content.innerHTML = '<div class="dashboard-dialog-state" role="alert"><h3>Daftar order belum dapat dimuat</h3><p>Periksa koneksi atau sesi login Anda, lalu coba lagi.</p><button class="button secondary" type="button" data-order-retry>Coba lagi</button></div>';
        } finally {
            if (request === sequence) content.setAttribute('aria-busy', 'false');
        }
    }

    root.addEventListener('click', event => {
        if (event.defaultPrevented || event.button > 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest?.('a[data-dashboard-orders]');
        if (!link) return;
        const url = new URL(link.getAttribute?.('href') || link.href?.baseVal || link.href, baseUrl);
        if (url.origin !== origin) return;
        event.preventDefault();
        trigger = link;
        title.textContent = link.dataset.modalTitle || 'Daftar Work Order';
        fullList.href = url.href;
        url.searchParams.set('modal', '1');
        if (!dialog.open) dialog.showModal();
        load(url);
    });
    dialog.addEventListener('click', event => {
        if (event.target.closest?.('[data-order-dialog-close]')) { dialog.close(); return; }
        if (event.target.closest?.('[data-order-retry]')) { load(currentUrl); return; }
        const link = event.target.closest?.('a[data-order-page]');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button > 0) return;
        const url = new URL(link.href, baseUrl);
        if (url.origin !== origin) return;
        event.preventDefault();
        url.searchParams.set('modal', '1');
        load(url);
    });
    dialog.addEventListener('close', () => {
        ++sequence;
        controller?.abort();
        content.setAttribute('aria-busy', 'false');
        content.innerHTML = '';
        if (trigger?.isConnected) trigger.focus();
    });
}

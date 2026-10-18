const themeButtons = document.querySelectorAll('[data-theme-toggle]');
function themeLabel() {
    const dark = document.documentElement.classList.contains('dark');
    themeButtons.forEach(button => {
        const label = dark ? 'Aktifkan mode terang' : 'Aktifkan mode gelap';
        button.setAttribute('aria-label', label);
        button.setAttribute('title', label);
        button.setAttribute('aria-pressed', String(dark));
    });
}
themeLabel();
themeButtons.forEach(button => button.addEventListener('click', () => {
    const dark = document.documentElement.classList.toggle('dark');
    try { localStorage.setItem('olo-theme', dark ? 'dark' : 'light'); } catch (_) {}
    themeLabel();
}));
const menu = document.querySelector('#menu-toggle');
const sidebar = document.querySelector('#sidebar');
const backdrop = document.querySelector('.drawer-backdrop');
const mobile = matchMedia('(max-width: 800px)');
function syncDrawerAccess() { if (sidebar) sidebar.inert = mobile.matches && !sidebar.classList.contains('open'); }
function closeMenu() { sidebar?.classList.remove('open'); menu?.setAttribute('aria-expanded', 'false'); if (backdrop) backdrop.hidden = true; syncDrawerAccess(); }
syncDrawerAccess();
mobile.addEventListener('change', () => { closeMenu(); });
menu?.addEventListener('click', () => { const opened = sidebar.classList.toggle('open'); menu.setAttribute('aria-expanded', String(opened)); backdrop.hidden = !opened; syncDrawerAccess(); if (opened) sidebar.querySelector('a')?.focus(); });
backdrop?.addEventListener('click', () => { closeMenu(); menu?.focus(); });
document.addEventListener('keydown', event => {
    if (!sidebar?.classList.contains('open')) return;
    if (event.key === 'Escape') { closeMenu(); menu?.focus(); }
    if (event.key === 'Tab' && mobile.matches) {
        const items = [...sidebar.querySelectorAll('a[href], button:not(:disabled)')];
        const first = items[0], last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
});
document.querySelectorAll('form[data-loading]').forEach(form => form.addEventListener('submit', event => {
    if (form.dataset.submitting) { event.preventDefault(); return; }
    form.dataset.submitting = '1';
    form.querySelectorAll('button[type="submit"], button:not([type])').forEach(button => { button.disabled = true; button.setAttribute('aria-busy', 'true'); button.textContent = form.dataset.loading; });
}));
window.addEventListener('pageshow', event => { if (event.persisted) location.reload(); });
const poll = document.querySelector('[data-sync-poll]');
if (poll) {
    let failures = 0;
    async function refresh() {
        try {
            const response = await fetch(poll.dataset.syncPoll, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error();
            const result = await response.json();
            if (!['queued', 'running', 'retrying'].includes(result.state)) { location.reload(); return; }
            poll.querySelector('[data-poll-state]').textContent = result.state;
            poll.querySelector('[data-poll-error]').textContent = result.error || '';
            failures = 0;
        } catch (_) { failures++; poll.querySelector('[data-poll-error]').textContent = ' Pembaruan status gagal. Periksa koneksi atau muat ulang halaman.'; }
        if (failures < 10) setTimeout(refresh, 3000);
    }
    setTimeout(refresh, 3000);
}
const userForm = document.querySelector('[data-user-form]');
if (userForm) {
    const role = userForm.querySelector('[name=role]');
    const region = userForm.querySelector('[name=id_regions]');
    const sto = userForm.querySelector('[name=id_service_areas]');
    const checkboxes = [...userForm.querySelectorAll('[name="branches[]"]')];
    const status = userForm.querySelector('[data-sto-status]');
    function filterLocations(changed = false) {
        const area = role.selectedOptions[0]?.dataset.areaScope === '1';
        const selected = checkboxes.filter(item => item.checked).map(item => item.value);
        userForm.querySelector('[data-role-scope-hint]').textContent = area
            ? 'Role Area memiliki akses seluruh Area. Lokasi utama bersifat opsional.'
            : 'Pilih minimal satu cabang. Pengguna hanya dapat mengakses cabang yang ditugaskan.';
        const previous = sto.value;
        let available = 0;
        let needsReview = false;
        for (const option of sto.options) {
            if (!option.value) continue;
            const allowed = (!region.value || option.dataset.region === region.value) && (area || selected.includes(option.dataset.branch));
            option.hidden = !allowed;
            option.disabled = !allowed;
            if (allowed) available++;
            if (!changed && option.selected && !allowed) {
                needsReview = true; option.hidden = false; option.disabled = false;
            }
        }
        if (sto.selectedOptions[0]?.disabled) sto.value = '';
        status.textContent = needsReview ? 'STO tersimpan perlu ditinjau. Pilih lokasi yang aktif dan sesuai sebelum menyimpan.' : changed && previous && !sto.value
            ? 'STO sebelumnya tidak sesuai pilihan baru. Pilih kembali STO yang sesuai.'
            : available ? `${available} STO tersedia. Lokasi utama tidak menambah hak akses.`
            : 'Belum ada STO sesuai pilihan ini. Periksa region, cabang, atau mapping STO.';
    }
    sto.addEventListener('change', () => { if (sto.value && !region.value) region.value = sto.selectedOptions[0].dataset.region; filterLocations(true); });
    region.addEventListener('change', () => filterLocations(true));
    role.addEventListener('change', () => filterLocations(true));
    checkboxes.forEach(input => input.addEventListener('change', () => filterLocations(true)));
    filterLocations();
}
document.querySelectorAll('[data-chart-point]').forEach(point => {
    function describe() {
        const caption = point.closest('.panel-body')?.querySelector('[data-chart-caption]');
        if (caption) caption.textContent = `${point.dataset.label}: ${point.dataset.count} ${point.closest('.area-line-chart') ? 'PS terkonfirmasi' : 'order complete'}.`;
    }
    point.addEventListener('click', describe);
    point.addEventListener('keydown', event => { if (['Enter', ' '].includes(event.key)) { event.preventDefault(); describe(); } });
});
const progressForm = document.querySelector('[data-progress-form]');
if (progressForm) {
    const category = progressForm.querySelector('[name=progress_category]');
    const hint = progressForm.querySelector('[data-progress-hint]');
    function progressHint() {
        hint.textContent = category.value === 'KENDALA'
            ? 'Jelaskan kendala pada catatan. Jika belum ada kendala aktif, catatan ini otomatis menjadi detail kendala baru.'
            : ['CLOSE', 'CLOSE ON DESK'].includes(category.value)
                ? 'Selesaikan kendala aktif sebelum menutup order. Koreksi setelah ditutup memerlukan Admin Area.'
                : 'Tuliskan kondisi pekerjaan dan tindak lanjut. Catatan tersimpan pada riwayat order.';
    }
    category.addEventListener('change', progressHint);
    progressHint();
}

import { setupJtBoq } from './jt-boq';
import { setupDashboardTables } from './dashboard-tables';
import { setupFormConfirmation } from './form-confirmation';

setupFormConfirmation(document, message => window.confirm(message));
setupDashboardTables(document);
setupJtBoq(document);

const kmlPreview = document.querySelector('[data-kml-preview]');
if (kmlPreview) {
    Promise.all([import('leaflet'), import('./kml-map'), import('leaflet/dist/leaflet.css')])
        .then(([leaflet, module]) => module.setupKmlMap(kmlPreview, { leaflet: leaflet.default ?? leaflet }))
        .catch(() => {
            kmlPreview.querySelector('[data-kml-status]').textContent = 'Komponen peta gagal dimuat. Muat ulang halaman atau unduh KML.';
            kmlPreview.querySelector('[data-kml-status]').classList.add('danger');
            kmlPreview.querySelector('[data-kml-map]').setAttribute('aria-busy', 'false');
        });
}

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
        const items = [...sidebar.querySelectorAll('a[href], button:not(:disabled), summary')].filter(item => item.getClientRects().length > 0);
        const first = items[0], last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
});
document.querySelectorAll('form[data-loading]').forEach(form => form.addEventListener('submit', event => {
    if (event.defaultPrevented) return;
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
            : 'Pilih minimal satu Branch. Pengguna hanya dapat mengakses Branch yang ditugaskan.';
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
            : 'Belum ada STO sesuai pilihan ini. Periksa region, Branch, atau mapping STO.';
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
document.querySelectorAll('[data-open-devices]').forEach(link => link.addEventListener('click', () => { const panel = document.getElementById('order-devices'); if (panel) panel.open = true; }));

const orderFilters = document.querySelector('[data-order-filters]');
if (orderFilters && matchMedia('(max-width: 700px)').matches && orderFilters.dataset.hasErrors !== '1') orderFilters.open = false;

const excelFile = document.querySelector('[data-upload-limit]');
if (excelFile) {
    const feedback = document.querySelector('[data-upload-feedback]');
    excelFile.addEventListener('change', () => {
        const file = excelFile.files?.[0];
        const limit = Number(excelFile.dataset.uploadLimit);
        const tooLarge = file && file.size > limit;
        const size = bytes => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 }).format(bytes / 1024 / 1024);
        const message = tooLarge ? `Ukuran file ${size(file.size)} MB melebihi batas ${size(limit)} MB. Pilih file yang lebih kecil.` : '';
        excelFile.setCustomValidity(message);
        if (feedback) {
            feedback.textContent = message || (file ? `Ukuran file ${size(file.size)} MB · siap diunggah.` : '');
            feedback.classList.toggle('danger', Boolean(tooLarge));
        }
        if (tooLarge) excelFile.reportValidity();
    });
}

const masterForm = document.querySelector('[data-master-form]');
if (masterForm?.querySelector('#master-branch')) {
    const region = masterForm.querySelector('#master-region');
    const branch = masterForm.querySelector('#master-branch');
    const status = masterForm.querySelector('#master-active');
    const hint = masterForm.querySelector('[data-master-branch-hint]');
    function filterMasterBranches(changed = false) {
        let needsReview = false;
        for (const option of branch.options) {
            if (!option.value) continue;
            const allowed = !region.value || option.dataset.region === region.value;
            option.hidden = !allowed;
            option.disabled = !allowed;
            if (!changed && option.selected && !allowed) {
                needsReview = true;
                option.hidden = false;
                option.disabled = false;
            }
        }
        if (branch.selectedOptions[0]?.disabled) branch.value = '';
        branch.required = region.required = status.value === '1';
        hint.textContent = needsReview ? 'Mapping tersimpan perlu ditinjau. Pilih Branch yang sesuai dengan Region sebelum menyimpan.' : 'Service Area aktif wajib memiliki Branch dan Region yang saling sesuai.';
    }
    region.addEventListener('change', () => filterMasterBranches(true));
    branch.addEventListener('change', () => {
        if (branch.value && !region.value) region.value = branch.selectedOptions[0].dataset.region;
        filterMasterBranches(true);
    });
    status.addEventListener('change', () => filterMasterBranches());
    filterMasterBranches();
}

// Floating tooltips stay visible outside scrollable tables and respond to keyboard focus.
const masterTooltipTargets = document.querySelectorAll('[data-master-tooltip]');
if (masterTooltipTargets.length) {
    const tooltip = document.createElement('div');
    tooltip.id = 'master-action-tooltip';
    tooltip.className = 'master-tooltip';
    tooltip.setAttribute('role', 'tooltip');
    tooltip.hidden = true;
    document.body.append(tooltip);
    let currentTarget = null;
    let previousDescription = null;
    function hideMasterTooltip() {
        if (currentTarget) {
            if (previousDescription) currentTarget.setAttribute('aria-describedby', previousDescription);
            else currentTarget.removeAttribute('aria-describedby');
        }
        currentTarget = null;
        tooltip.hidden = true;
    }
    function showMasterTooltip(target) {
        hideMasterTooltip();
        currentTarget = target;
        previousDescription = target.getAttribute('aria-describedby');
        tooltip.textContent = target.dataset.masterTooltip;
        tooltip.hidden = false;
        target.setAttribute('aria-describedby', [previousDescription, tooltip.id].filter(Boolean).join(' '));
        const anchor = target.getBoundingClientRect();
        const size = tooltip.getBoundingClientRect();
        const left = Math.max(12, Math.min(anchor.left + anchor.width / 2 - size.width / 2, window.innerWidth - size.width - 12));
        const top = anchor.top - size.height - 8 >= 8 ? anchor.top - size.height - 8 : Math.max(8, Math.min(anchor.bottom + 8, window.innerHeight - size.height - 8));
        tooltip.style.left = `${left}px`;
        tooltip.style.top = `${top}px`;
    }
    masterTooltipTargets.forEach(target => {
        target.removeAttribute('title');
        target.addEventListener('mouseenter', () => showMasterTooltip(target));
        target.addEventListener('focus', () => showMasterTooltip(target));
        target.addEventListener('mouseleave', hideMasterTooltip);
        target.addEventListener('blur', hideMasterTooltip);
        target.addEventListener('click', hideMasterTooltip);
    });
    document.addEventListener('keydown', event => { if (event.key === 'Escape') hideMasterTooltip(); });
    document.addEventListener('scroll', hideMasterTooltip, true);
    window.addEventListener('resize', hideMasterTooltip);
}

if (location.hash === '#order-devices') { const devicePanel = document.getElementById('order-devices'); if (devicePanel) devicePanel.open = true; }

const logoutDialog = document.querySelector('#logout-confirmation');
const logoutTrigger = document.querySelector('[data-logout-open]');
if (logoutDialog && logoutTrigger && typeof logoutDialog.showModal === 'function') {
    logoutTrigger.addEventListener('click', event => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        if (!logoutDialog.open) logoutDialog.showModal();
    });
    logoutDialog.querySelector('[data-logout-cancel]').addEventListener('click', () => logoutDialog.close());
    logoutDialog.querySelector('[data-logout-confirm]').addEventListener('submit', () => {
        logoutDialog.querySelector('[data-logout-cancel]').disabled = true;
    });
    logoutDialog.addEventListener('cancel', event => {
        if (logoutDialog.querySelector('[data-logout-confirm]').dataset.submitting) event.preventDefault();
    });
}

export function setupMaterialTemplate(root, clipboard = globalThis.navigator?.clipboard) {
    const button = root.querySelector('[data-copy-material]');
    if (!button) return;
    const field = root.getElementById('material-template');
    const status = root.querySelector('[data-copy-material-status]');
    button.addEventListener('click', async () => {
        try {
            if (!clipboard?.writeText) throw new Error('Clipboard unavailable');
            await clipboard.writeText(field.value);
            status.textContent = 'Template disalin. Lengkapi lalu kirim melalui Telegram.';
        } catch {
            field.focus();
            field.select();
            status.textContent = 'Salin otomatis belum tersedia. Template sudah dipilih; gunakan Salin / Ctrl+C.';
        }
    });
}

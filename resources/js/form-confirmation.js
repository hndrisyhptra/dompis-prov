export function setupFormConfirmation(root, confirmFallback) {
    const dialog = root.querySelector('#data-confirmation');
    if (!dialog) return () => {};

    const title = dialog.querySelector('[data-confirm-title]');
    const description = dialog.querySelector('[data-confirm-description]');
    const context = dialog.querySelector('[data-confirm-context]');
    const yes = dialog.querySelector('[data-confirm-yes]');
    const no = dialog.querySelector('[data-confirm-no]');
    const approved = new WeakSet();
    let pending = null;

    function cancel() {
        pending = null;
        dialog.close();
    }

    function submitConfirmed() {
        const operation = pending;
        if (!operation) return;
        pending = null;
        dialog.close();
        const { form, submitter } = operation;
        if (!form.isConnected || (submitter && (!submitter.isConnected || submitter.form !== form || submitter.disabled))) return;
        approved.add(form);
        try {
            // requestSubmit preserves native validation, CSRF fields, file inputs and submitter intent.
            form.requestSubmit(submitter || undefined);
        } finally {
            approved.delete(form);
        }
    }

    function handleSubmit(event) {
        const form = event.target;
        const method = form.method?.toUpperCase();
        if (!form.closest?.('.page') || !method || ['GET', 'DIALOG'].includes(method) || form.hasAttribute('data-confirm-skip')) return;
        if (event.defaultPrevented) return;
        if (form.dataset.submitting) { event.preventDefault(); return; }
        if (approved.has(form)) { approved.delete(form); return; }
        if (!form.checkValidity()) { event.preventDefault(); form.reportValidity(); return; }

        const deleting = (form.querySelector('[name="_method"]')?.value || method).toUpperCase() === 'DELETE';
        const message = form.dataset.confirmMessage || (deleting ? 'Apakah Anda yakin ingin menghapus data ini?' : 'Apakah Anda yakin ingin menyimpan data ini?');
        if (typeof dialog.showModal !== 'function' || typeof form.requestSubmit !== 'function') {
            if (!confirmFallback(message)) event.preventDefault();
            return;
        }
        event.preventDefault();
        if (dialog.open) return;
        pending = { form, submitter: event.submitter };
        title.textContent = deleting ? 'Konfirmasi hapus data' : 'Konfirmasi simpan data';
        description.textContent = message;
        context.textContent = event.submitter?.textContent?.trim().replace(/\s+/g, ' ') || 'Periksa data sebelum melanjutkan.';
        yes.textContent = deleting ? 'Ya, Hapus' : 'Ya, Simpan';
        yes.classList.toggle('primary', !deleting);
        yes.classList.toggle('master-delete-button', deleting);
        dialog.showModal();
    }

    const clearPending = () => { pending = null; };
    no.addEventListener('click', cancel);
    yes.addEventListener('click', submitConfirmed);
    dialog.addEventListener('cancel', clearPending);
    dialog.addEventListener('close', clearPending);
    root.addEventListener('submit', handleSubmit, true);

    return () => {
        root.removeEventListener('submit', handleSubmit, true);
        no.removeEventListener('click', cancel);
        yes.removeEventListener('click', submitConfirmed);
        dialog.removeEventListener('cancel', clearPending);
        dialog.removeEventListener('close', clearPending);
    };
}

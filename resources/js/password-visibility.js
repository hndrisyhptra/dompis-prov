export function setupPasswordVisibility(root) {
    root.querySelectorAll('[data-password-toggle]').forEach(button => {
        const field = root.getElementById(button.getAttribute('aria-controls'));
        if (!field || field.type !== 'password') return;
        button.hidden = false;
        button.addEventListener('click', () => {
            const visible = field.type === 'password';
            field.type = visible ? 'text' : 'password';
            const label = visible ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi';
            button.setAttribute('aria-pressed', String(visible));
            button.setAttribute('aria-label', label);
            button.setAttribute('title', label);
            button.querySelector('[data-password-show]').hidden = visible;
            button.querySelector('[data-password-hide]').hidden = !visible;
        });
    });
}

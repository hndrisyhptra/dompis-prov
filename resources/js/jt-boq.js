export function setupJtBoq(root) {
    const options = root.querySelector('[data-jt-boq-options]');
    if (!options) return;
    const form = options.closest('form');
    const category = form.querySelector('#progress_category');
    const save = form.querySelector('[data-normal-progress-save]');
    const next = form.querySelector('[data-boq-next-value]');
    function update() {
        const jt = category.value === 'JT';
        options.hidden = !jt;
        options.querySelectorAll('button').forEach(button => { button.disabled = !jt; });
        save.hidden = jt;
        save.disabled = jt;
        next.removeAttribute('name');
    }
    category.addEventListener('change', update);
    form.addEventListener('submit', event => {
        if (event.defaultPrevented) return;
        // Preserve intent when the existing loading handler disables named submit buttons.
        if (category.value === 'JT' && event.submitter?.name === 'boq_next') {
            next.name = 'boq_next';
            next.value = event.submitter.value;
        } else next.removeAttribute('name');
    });
    update();
}

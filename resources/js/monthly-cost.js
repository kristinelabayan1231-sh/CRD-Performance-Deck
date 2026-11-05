// CRD - MC: Edit / + Add person open a row's form in place; Edit settings opens a pop-up.
export function initMonthlyCost() {
    const page = document.querySelector('[data-monthly-cost]');
    if (!page) return;

    const show = (key, editing) => {
        const form = page.querySelector(`[data-cost-form="${key}"]`);
        const view = page.querySelector(`[data-cost-view="${key}"]`);
        form.hidden = !editing;
        if (view) view.hidden = editing;
        const empty = page.querySelector('[data-cost-empty]');
        if (empty && key === 'new') empty.hidden = editing;
        if (editing) form.querySelector('input').focus();
    };

    page.addEventListener('click', (event) => {
        const edit = event.target.closest('[data-cost-edit]');
        const cancel = event.target.closest('[data-cost-cancel]');
        if (edit) show(edit.dataset.costEdit, true);
        if (cancel) show(cancel.dataset.costCancel, false);
    });
    page.querySelector('[data-cost-add-open]').addEventListener('click', () => show('new', true));

    const dialog = document.getElementById('cost-settings');
    page.querySelector('[data-cost-settings-open]').addEventListener('click', () => dialog.showModal());
    dialog.querySelectorAll('[data-cost-settings-close]').forEach((button) => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('click', (event) => event.target === dialog && dialog.close());
    // Sent back with errors: open again with what was typed.
    if (dialog.hasAttribute('data-open')) dialog.showModal();
}

// CRD - MC: Edit / + Add person open a row's form in place; a daily-rated pay type fills in the salary; Edit settings opens a pop-up.
export function initMonthlyCost() {
    const page = document.querySelector('[data-monthly-cost]');
    if (!page) return;
    const rates = JSON.parse(page.dataset.rates);
    const days = Number(page.dataset.days);
    const peso = (value) => `₱${value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

    // Trainee / Probationary / Regular: rate × working days, read-only. Fixed salary: typed in.
    const syncPay = (form) => {
        const type = form.querySelector('[data-cost-pay]').value;
        const salary = form.querySelector('[data-cost-salary]');
        const hint = form.querySelector('[data-cost-hint]');
        const daily = rates[type];
        salary.readOnly = daily !== undefined;
        salary.required = daily === undefined;
        if (daily !== undefined) salary.value = (daily * days).toFixed(2);
        hint.textContent = daily !== undefined ? `${peso(daily)} × ${days} days` : '';
    };

    const show = (key, editing) => {
        const form = page.querySelector(`[data-cost-form="${key}"]`);
        const view = page.querySelector(`[data-cost-view="${key}"]`);
        form.hidden = !editing;
        if (view) view.hidden = editing;
        const empty = page.querySelector('[data-cost-empty]');
        if (empty && key === 'new') empty.hidden = editing;
        if (editing) {
            syncPay(form);
            form.querySelector('input').focus();
        }
    };

    page.querySelectorAll('[data-cost-form]').forEach((form) => {
        form.querySelector('[data-cost-pay]').addEventListener('change', () => syncPay(form));
        if (!form.hidden) syncPay(form);
    });
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

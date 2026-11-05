// Editable list of names ([data-account-list]): "Add account" copies the <template> row, × removes a row.
export function initAccountLists() {
    document.querySelectorAll('[data-account-list]').forEach((list) => {
        const rows = list.querySelector('[data-account-rows]');
        const template = list.querySelector('template[data-account-row]');

        list.querySelector('[data-account-add]')?.addEventListener('click', () => {
            rows.append(template.content.cloneNode(true));
            rows.lastElementChild.querySelector('input')?.focus();
        });

        rows.addEventListener('click', (event) => {
            const remove = event.target.closest('[data-account-remove]');
            if (remove) remove.closest('[data-account-item]').remove();
        });
    });
}

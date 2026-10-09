// Customer Database: a row opens the customer's details (status breakdown, product CLTV, orders) in a pop-up.
export function initCustomers() {
    const dialog = document.getElementById('customer-dialog');
    if (!dialog) return;
    const body = dialog.querySelector('[data-customer-body]');
    let current = null;

    const open = async (url) => {
        current = url;
        body.innerHTML = '<p class="py-10 text-center text-sm text-muted">Loading…</p>';
        dialog.showModal();

        try {
            const response = await fetch(url, { headers: { Accept: 'text/html' } });
            if (!response.ok) throw new Error(response.status);
            const html = await response.text();
            // A slower earlier request mustn't replace the customer opened since.
            if (current === url) body.innerHTML = html;
        } catch {
            if (current === url) body.innerHTML = '<p role="alert" class="py-10 text-center text-sm text-coral-700">Couldn\'t load this customer. Try again.</p>';
        }
    };

    document.addEventListener('click', (event) => {
        const row = event.target.closest('[data-customer-url]');
        if (row) open(row.dataset.customerUrl);
    });
    document.addEventListener('keydown', (event) => {
        const row = event.target.closest?.('[data-customer-url]');
        if (row && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            open(row.dataset.customerUrl);
        }
    });

    dialog.querySelector('[data-customer-close]').addEventListener('click', () => dialog.close());
    // Click on the backdrop closes it.
    dialog.addEventListener('click', (event) => event.target === dialog && dialog.close());
    dialog.addEventListener('close', () => (current = null));
}

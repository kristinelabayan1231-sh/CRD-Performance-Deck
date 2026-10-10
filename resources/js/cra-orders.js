// Conversion Breakdown: clicking a CRA's gross sales opens the orders behind it in a pop-up.
export function initCraOrders() {
    const dialog = document.getElementById('cra-orders-dialog');
    if (!dialog) return;
    const body = dialog.querySelector('[data-cra-orders-body]');
    let current = null;

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-cra-orders]');
        if (!button) return;

        const url = button.dataset.craOrders;
        current = url;
        body.innerHTML = '<p class="py-10 text-center text-sm text-muted">Loading…</p>';
        dialog.showModal();

        try {
            const response = await fetch(url, { headers: { Accept: 'text/html' } });
            if (!response.ok) throw new Error(response.status);
            const html = await response.text();
            // A slower earlier request mustn't replace the CRA opened since.
            if (current === url) body.innerHTML = html;
        } catch {
            if (current === url) body.innerHTML = '<p role="alert" class="py-10 text-center text-sm text-coral-700">Couldn\'t load the orders. Try again.</p>';
        }
    });

    dialog.querySelector('[data-cra-orders-close]').addEventListener('click', () => dialog.close());
    // Click on the backdrop closes it.
    dialog.addEventListener('click', (event) => event.target === dialog && dialog.close());
    dialog.addEventListener('close', () => (current = null));
}

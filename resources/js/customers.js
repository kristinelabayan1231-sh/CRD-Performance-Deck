import { loadingMarkup } from './spinner';

// Customer Database: a row opens the customer's details (status breakdown, product CLTV, orders) in a pop-up.
export function initCustomers() {
    const dialog = document.getElementById('customer-dialog');
    if (!dialog) return;
    const body = dialog.querySelector('[data-customer-body]');
    let current = null;

    const open = async (url) => {
        current = url;
        body.innerHTML = loadingMarkup();
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

    // Fields inside a row (High AOV CVR & VIP's dropdowns and remarks) keep working instead of opening it.
    const field = 'select, input, textarea, button, a, label';
    document.addEventListener('click', (event) => {
        const row = event.target.closest('[data-customer-url]');
        if (row && !event.target.closest(field)) open(row.dataset.customerUrl);
    });
    document.addEventListener('keydown', (event) => {
        const row = event.target.closest?.('[data-customer-url]');
        if (row && !event.target.closest(field) && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            open(row.dataset.customerUrl);
        }
    });

    dialog.querySelector('[data-customer-close]').addEventListener('click', () => dialog.close());
    // Click on the backdrop closes it.
    dialog.addEventListener('click', (event) => event.target === dialog && dialog.close());

    // Full view: the pop-up fills the screen (for presenting); kept for the next customer opened.
    const setFull = (full) => {
        dialog.toggleAttribute('data-full', full);
        try {
            localStorage.setItem('customerDialogFull', full ? '1' : '');
        } catch {}
    };
    try {
        setFull(localStorage.getItem('customerDialogFull') === '1');
    } catch {}
    dialog.querySelector('[data-customer-full]').addEventListener('click', () => setFull(!dialog.hasAttribute('data-full')));
    // Esc leaves full view first, then closes.
    dialog.addEventListener('cancel', (event) => {
        if (dialog.hasAttribute('data-full')) {
            event.preventDefault();
            setFull(false);
        }
    });

    // Eye button: hide or show names and numbers without closing; the list behind follows once the pop-up closes.
    const privacy = dialog.querySelector('[data-customer-privacy]');
    let reloadOnClose = false;
    privacy?.addEventListener('click', async () => {
        privacy.disabled = true;
        try {
            const response = await fetch(privacy.dataset.customerPrivacy, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '' },
            });
            if (!response.ok) throw new Error(response.status);
            const { hidden } = await response.json();
            privacy.setAttribute('aria-pressed', hidden ? 'true' : 'false');
            reloadOnClose = true;
            if (current) open(current);
        } catch {
            body.insertAdjacentHTML('afterbegin', '<p role="alert" class="mb-3 text-sm text-coral-700">Couldn\'t change it. Try again.</p>');
        } finally {
            privacy.disabled = false;
        }
    });

    dialog.addEventListener('close', () => {
        current = null;
        if (reloadOnClose) window.location.reload();
    });
}

// Actual Income: each orange box saves when it changes (empty clears it), then the cards are redrawn from the server.
async function send(url, body) {
    const response = await fetch(url, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
        },
        body: JSON.stringify(body),
    });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
}

export function initActualIncome() {
    const root = document.querySelector('[data-actual-income]');
    if (!root) return;
    const host = root.querySelector('[data-actual-income-cards]');

    const redraw = async () => {
        const response = await fetch(root.dataset.cardsUrl, { headers: { 'X-Actual-Income-Cards': '1', Accept: 'text/html' } });
        if (!response.ok) return;
        // Keep the box the user tabbed into focused after the cards are redrawn.
        const focused = document.activeElement?.closest?.('[data-actual-income-input]');
        const selector = focused ? `[data-actual-income-input][data-cra="${focused.dataset.cra}"][data-field="${focused.dataset.field}"]` : null;
        host.innerHTML = await response.text();
        if (selector) host.querySelector(selector)?.focus();
    };

    root.addEventListener('change', async (event) => {
        const input = event.target.closest('[data-actual-income-input]');
        if (!input) return;
        const raw = input.value.replace(/[,%₱\s]/g, '');
        const value = raw === '' ? null : Number(raw);
        if (value !== null && (Number.isNaN(value) || value < 0)) {
            input.classList.add('border-coral');
            input.title = 'Type a number of 0 or more.';
            return;
        }
        try {
            await send(root.dataset.url, { month: root.dataset.month, cra: input.dataset.cra || null, field: input.dataset.field, value });
            input.classList.remove('border-coral');
            input.title = '';
            await redraw();
        } catch {
            input.classList.add('border-coral');
            input.title = "Couldn't save; try again.";
        }
    });
}

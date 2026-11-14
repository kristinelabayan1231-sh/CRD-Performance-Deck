// CRA Gross Sales & IBSC (edit mode): each box saves when it changes (empty clears it), then the table is redrawn.
export function initCraGrossSales() {
    const root = document.querySelector('[data-cra-gross-sales]');
    if (!root) return;
    const host = root.querySelector('[data-cra-gross-sales-table]');

    const redraw = async () => {
        const response = await fetch(root.dataset.tableUrl, { headers: { 'X-Cra-Gross-Sales-Table': '1', Accept: 'text/html' } });
        if (!response.ok) return;
        // Keep the box the user tabbed into focused, and the table scrolled where it was.
        const focused = document.activeElement?.closest?.('[data-cra-gross-sales-input]');
        const selector = focused
            ? `[data-cra-gross-sales-input][data-cra="${focused.dataset.cra}"][data-month="${focused.dataset.month}"][data-field="${focused.dataset.field}"]`
            : null;
        const scroller = () => host.querySelector('.overflow-x-auto');
        const scrolled = scroller()?.scrollLeft ?? 0;
        host.innerHTML = await response.text();
        if (scroller()) scroller().scrollLeft = scrolled;
        if (selector) host.querySelector(selector)?.focus({ preventScroll: true });
    };

    root.addEventListener('change', async (event) => {
        const input = event.target.closest('[data-cra-gross-sales-input]');
        if (!input) return;
        const raw = input.value.replace(/[,₱\s]/g, '');
        const value = raw === '' ? null : Number(raw);
        if (value !== null && Number.isNaN(value)) {
            input.classList.add('border-coral');
            input.title = 'Type a number.';
            return;
        }
        try {
            const response = await fetch(root.dataset.url, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                },
                body: JSON.stringify({ month: input.dataset.month, cra: Number(input.dataset.cra), field: input.dataset.field, value }),
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            input.classList.remove('border-coral');
            input.title = '';
            await redraw();
        } catch {
            input.classList.add('border-coral');
            input.title = "Couldn't save; try again.";
        }
    });
}

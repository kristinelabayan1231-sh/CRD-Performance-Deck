// Product Performance: a product's chip above the chart shows or hides its line.
export function initProductPerformance() {
    initInsightsFold();

    const chips = document.querySelector('[data-pp-chart]');
    if (!chips) return;

    chips.addEventListener('click', (event) => {
        const chip = event.target.closest('[data-pp-toggle]');
        if (!chip) return;

        const shown = chip.getAttribute('aria-pressed') !== 'true';
        chip.setAttribute('aria-pressed', String(shown));
        const line = document.querySelector(`[data-pp-line="${chip.dataset.ppToggle}"]`);
        if (shown) line?.removeAttribute('display');
        else line?.setAttribute('display', 'none');
    });
}

// Insights panel: remember whether it was folded up, on this browser only.
export function initInsightsFold() {
    const details = document.querySelector('[data-pp-insights]');
    if (!details) return;

    try {
        if (localStorage.getItem('pp-insights') === 'closed') details.open = false;
    } catch {}

    details.addEventListener('toggle', () => {
        try {
            localStorage.setItem('pp-insights', details.open ? 'open' : 'closed');
        } catch {}
    });
}

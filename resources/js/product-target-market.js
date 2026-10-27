// Product Target Market: clicking a product's heatmap row shows its panel.
export function initProductTargetMarket() {
    const root = document.querySelector('[data-target-market]');
    if (!root) return;

    const show = (row) => {
        root.querySelectorAll('[data-tm-row]').forEach((r) => r.setAttribute('aria-selected', r === row ? 'true' : 'false'));
        root.querySelectorAll('[data-tm-panel]').forEach((panel) => (panel.hidden = panel.dataset.tmPanel !== row.dataset.tmRow));
    };

    root.addEventListener('click', (event) => {
        const row = event.target.closest('[data-tm-row]');
        if (row) show(row);
    });
    root.addEventListener('keydown', (event) => {
        const row = event.target.closest('[data-tm-row]');
        if (row && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            show(row);
        }
    });
}

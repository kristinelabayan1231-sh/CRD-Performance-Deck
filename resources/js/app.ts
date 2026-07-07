type ViewMode = 'chart' | 'table';

function setBreakdownView(mode: ViewMode): void {
    document.querySelectorAll<HTMLElement>('[data-chart-view]').forEach((el) => {
        el.classList.toggle('hidden', mode !== 'chart');
    });

    document.querySelectorAll<HTMLElement>('[data-table-view]').forEach((el) => {
        el.classList.toggle('hidden', mode !== 'table');
    });

    document.querySelectorAll<HTMLElement>('[data-view-toggle]').forEach((button) => {
        const isActive = button.getAttribute('data-view-toggle') === mode;
        button.classList.toggle('bg-slate-900', isActive);
        button.classList.toggle('text-white', isActive);
        button.classList.toggle('text-slate-500', !isActive);
    });

    // Chart bars are compact enough for a 3-up grid; full breakdown tables
    // need the extra width, so table mode stacks the cards full-width.
    document.querySelectorAll<HTMLElement>('[data-breakdown-grid]').forEach((grid) => {
        grid.classList.toggle('lg:grid-cols-3', mode === 'chart');
    });
}

document.querySelectorAll<HTMLElement>('[data-view-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const mode = button.getAttribute('data-view-toggle') as ViewMode | null;

        if (mode) {
            setBreakdownView(mode);
        }
    });
});

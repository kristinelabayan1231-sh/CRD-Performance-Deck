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

function currentBreakdownView(): ViewMode {
    const tableButton = document.querySelector<HTMLElement>('[data-view-toggle="table"]');

    return tableButton?.classList.contains('bg-slate-900') ? 'table' : 'chart';
}

// Delegated (not per-element) so the listener survives the DOM swap that
// auto-refresh performs below.
document.addEventListener('click', (event) => {
    const button = (event.target as HTMLElement).closest<HTMLElement>('[data-view-toggle]');
    const mode = button?.getAttribute('data-view-toggle') as ViewMode | null;

    if (mode) {
        setBreakdownView(mode);
    }
});

function setTablePage(container: HTMLElement, page: number): void {
    const rows = container.querySelectorAll<HTMLElement>('[data-page]');
    let pageCount = 1;

    rows.forEach((row) => {
        const rowPage = Number(row.getAttribute('data-page'));
        pageCount = Math.max(pageCount, rowPage);
        row.classList.toggle('hidden', rowPage !== page);
    });

    const indicator = container.querySelector<HTMLElement>('[data-page-indicator]');
    if (indicator) {
        indicator.textContent = `Page ${page} of ${pageCount}`;
    }

    container.dataset.currentPage = String(page);

    const prevButton = container.querySelector<HTMLButtonElement>('[data-page-prev]');
    const nextButton = container.querySelector<HTMLButtonElement>('[data-page-next]');
    if (prevButton) prevButton.disabled = page <= 1;
    if (nextButton) nextButton.disabled = page >= pageCount;
}

function initPagination(): void {
    document.querySelectorAll<HTMLElement>('[data-paginated-table]').forEach((container) => {
        setTablePage(container, 1);
    });
}

document.addEventListener('click', (event) => {
    const target = event.target as HTMLElement;
    const prevButton = target.closest<HTMLElement>('[data-page-prev]');
    const nextButton = target.closest<HTMLElement>('[data-page-next]');
    const button = prevButton ?? nextButton;

    if (!button) {
        return;
    }

    const container = button.closest<HTMLElement>('[data-paginated-table]');
    if (!container) {
        return;
    }

    const currentPage = Number(container.dataset.currentPage ?? '1');
    setTablePage(container, prevButton ? currentPage - 1 : currentPage + 1);
});

initPagination();

const AUTO_REFRESH_INTERVAL_MS = 3 * 60 * 1000;

function initAutoRefresh(): void {
    const contentEl = document.getElementById('deck-content');
    const lastUpdatedEl = document.getElementById('last-updated');

    if (!contentEl) {
        return;
    }

    async function refresh(): Promise<void> {
        try {
            const response = await fetch(window.location.href);

            if (!response.ok) {
                throw new Error(`Refresh failed with status ${response.status}`);
            }

            const html = await response.text();
            const freshContent = new DOMParser().parseFromString(html, 'text/html').getElementById('deck-content');

            if (!freshContent || !contentEl) {
                return;
            }

            const mode = currentBreakdownView();
            contentEl.innerHTML = freshContent.innerHTML;
            setBreakdownView(mode);
            initPagination();

            if (lastUpdatedEl) {
                lastUpdatedEl.textContent = 'Updated just now';
                lastUpdatedEl.dataset.updatedAt = String(Date.now());
            }
        } catch (error) {
            console.error('Auto-refresh failed', error);
        }
    }

    setInterval(refresh, AUTO_REFRESH_INTERVAL_MS);

    // Keep the "Updated Xm ago" label honest between fetches too.
    setInterval(() => {
        if (!lastUpdatedEl?.dataset.updatedAt) {
            return;
        }

        const minutes = Math.floor((Date.now() - Number(lastUpdatedEl.dataset.updatedAt)) / 60000);
        lastUpdatedEl.textContent = minutes < 1 ? 'Updated just now' : `Updated ${minutes}m ago`;
    }, 30 * 1000);
}

initAutoRefresh();

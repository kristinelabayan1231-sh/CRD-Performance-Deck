// CRD Board on the dashboard: loads the Daily I.S. figures each time it opens, full screen and close.
export function initPoster() {
    const dialog = document.querySelector('[data-poster]');
    if (!dialog) return;

    const stage = dialog.querySelector('[data-poster-stage]');
    const board = dialog.querySelector('[data-poster-board]');
    const message = (text) => `<p class="rounded-2xl bg-[#211f2d] px-4 py-16 text-center text-sm text-[#bdb6cf]">${text}</p>`;

    const load = async () => {
        board.innerHTML = message('Loading the Daily I.S.…');
        try {
            const response = await fetch(board.dataset.url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok) throw new Error(response.statusText);
            board.innerHTML = await response.text();
        } catch {
            board.innerHTML = message('Could not load the board. Close it and try again.');
        }
    };

    document.querySelectorAll('[data-poster-open]').forEach((button) =>
        button.addEventListener('click', () => {
            dialog.showModal();
            load();
        }),
    );

    dialog.querySelector('[data-poster-close]')?.addEventListener('click', () => {
        if (document.fullscreenElement) document.exitFullscreen();
        dialog.close();
    });

    // Clicking the dimmed backdrop closes the board.
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });

    dialog.querySelector('[data-poster-fullscreen]')?.addEventListener('click', () => {
        if (document.fullscreenElement) {
            document.exitFullscreen();
        } else {
            stage.requestFullscreen?.().catch(() => {});
        }
    });
}

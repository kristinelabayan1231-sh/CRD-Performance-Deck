import { toPng } from 'html-to-image';

// CRD Board on the dashboard: loads the Daily I.S. figures each time it opens, screenshot (PNG), full screen and close.
export function initPoster() {
    const dialog = document.querySelector('[data-poster]');
    if (!dialog) return;

    const stage = dialog.querySelector('[data-poster-stage]');
    const board = dialog.querySelector('[data-poster-board]');
    const shot = dialog.querySelector('[data-poster-shot]');
    const message = (text) => `<p class="rounded-2xl bg-[#211f2d] px-4 py-16 text-center text-sm text-[#bdb6cf]">${text}</p>`;

    const load = async () => {
        board.innerHTML = message('Loading the Daily I.S.…');
        shot.disabled = true;
        try {
            const response = await fetch(board.dataset.url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok) throw new Error(response.statusText);
            board.innerHTML = await response.text();
            shot.disabled = false;
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

    // Saves the board as a PNG, e.g. "CRD Board - 2026-11-03.png".
    shot.addEventListener('click', async () => {
        const card = board.querySelector('[data-board-file]');
        const label = shot.querySelector('[data-poster-shot-label]');
        shot.disabled = true;
        label.textContent = 'Saving…';
        try {
            const link = document.createElement('a');
            link.href = await toPng(card, { pixelRatio: 2, skipFonts: true });
            link.download = card.dataset.boardFile;
            link.click();
        } catch {
            alert('Could not save the screenshot. Please try again.');
        } finally {
            shot.disabled = false;
            label.textContent = 'Screenshot';
        }
    });

    dialog.querySelector('[data-poster-fullscreen]')?.addEventListener('click', () => {
        if (document.fullscreenElement) {
            document.exitFullscreen();
        } else {
            stage.requestFullscreen?.().catch(() => {});
        }
    });
}

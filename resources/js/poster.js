// CRD poster board on the dashboard: open/close, full screen, clear, and
// money formatting. Typed values live only in the page; nothing is saved.
export function initPoster() {
    const dialog = document.querySelector('[data-poster]');
    if (!dialog) return;

    const stage = dialog.querySelector('[data-poster-stage]');
    const fields = [...dialog.querySelectorAll('input')];

    const formatMoney = (input) => {
        const raw = input.value.replace(/[^\d.-]/g, '');
        if (raw === '' || raw === '-' || Number.isNaN(Number(raw))) {
            input.style.color = '';
            return;
        }
        const value = Number(raw);
        input.value = value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        // A loss shows in red.
        input.style.color = input.hasAttribute('data-net') && value < 0 ? '#c4484c' : '';
    };

    dialog.querySelectorAll('[data-money]').forEach((input) => {
        input.addEventListener('blur', () => formatMoney(input));
        input.addEventListener('focus', () => (input.value = input.value.replace(/,/g, '')));
    });

    document.querySelectorAll('[data-poster-open]').forEach((button) =>
        button.addEventListener('click', () => {
            dialog.showModal();
            dialog.querySelector('[data-money]')?.focus();
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

    dialog.querySelector('[data-poster-clear]')?.addEventListener('click', () => {
        fields.forEach((input) => {
            input.value = input.dataset.default ?? '';
            input.style.color = '';
        });
    });

    dialog.querySelector('[data-poster-fullscreen]')?.addEventListener('click', () => {
        if (document.fullscreenElement) {
            document.exitFullscreen();
        } else {
            stage.requestFullscreen?.().catch(() => {});
        }
    });
}

// The app loads a new page for every link and form, and some pages take seconds. Whatever starts leaving the page (links,
// filters, saves, F5), a spinner shows until the next page arrives; pages that answer quickly never flash it.
const SHOW_AFTER_MS = 150;
const GIVE_UP_AFTER_MS = 90_000;
const STAYING_FOR_MS = 1_000;
const QUIET_KEY = 'quietReload';

// A reload the user didn't ask for (live pages): no spinner now, and no float-up on the fresh page (see the layout's head).
export function reloadQuietly() {
    try {
        sessionStorage.setItem(QUIET_KEY, '1');
    } catch {
        // Storage unavailable: the reload just shows the usual animation.
    }
    location.reload();
}

function isQuietReload() {
    try {
        return sessionStorage.getItem(QUIET_KEY) === '1';
    } catch {
        return false;
    }
}

export function initPageLoader() {
    const loader = document.querySelector('[data-page-loader]');
    if (!loader) return;

    const root = document.documentElement;
    let showTimer;
    let giveUpTimer;
    let staying = false;

    const hide = () => {
        clearTimeout(showTimer);
        clearTimeout(giveUpTimer);
        delete root.dataset.pageLoading;
        loader.hidden = true;
    };

    // Calling a number, an email or saving a file can fire beforeunload, but the page stays open.
    document.addEventListener(
        'click',
        (event) => {
            if (!event.target.closest?.('a[href^="tel:"], a[href^="mailto:"], a[download]')) return;
            staying = true;
            setTimeout(() => (staying = false), STAYING_FOR_MS);
        },
        true,
    );

    window.addEventListener('beforeunload', () => {
        if (staying || isQuietReload()) return;
        hide();
        showTimer = setTimeout(() => {
            root.dataset.pageLoading = '';
            loader.hidden = false;
        }, SHOW_AFTER_MS);
        // A cancelled load (Esc, the browser's stop button) tells the page nothing.
        giveUpTimer = setTimeout(hide, GIVE_UP_AFTER_MS);
    });

    // Back and forward can restore this page exactly as it was left, spinner included.
    window.addEventListener('pageshow', hide);
}

// The floating CRA schedule (supervisors and managers, any page): the calendar button opens a panel and loads every CRA's
// week or month into it from the server, only when opened. Buttons inside carry data-peek-url to move between weeks,
// months and views; the last view picked is remembered on this browser.

const VIEW_KEY = 'schedule-peek-view';

const remembered = () => {
    try {
        return localStorage.getItem(VIEW_KEY) === 'month' ? 'month' : 'week';
    } catch {
        return 'week';
    }
};

const remember = (view) => {
    try {
        localStorage.setItem(VIEW_KEY, view);
    } catch {
        // Storage blocked: start on Week next time.
    }
};

export function initSchedulePeek() {
    const root = document.querySelector('[data-schedule-peek]');
    if (!root) return;

    const toggle = root.querySelector('[data-peek-toggle]');
    const panel = root.querySelector('[data-peek-panel]');
    const content = root.querySelector('[data-peek-content]');
    let loaded = false;
    let request = 0;

    async function load(url) {
        const mine = ++request;
        // The button used is replaced with the new body: keep the keyboard on the same one.
        const focused = content.contains(document.activeElement) ? document.activeElement.getAttribute('aria-label') ?? document.activeElement.textContent : null;
        content.setAttribute('aria-busy', 'true');
        content.querySelector('[data-peek-body]')?.classList.add('opacity-50');
        try {
            const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' } });
            if (!response.ok) throw new Error(response.status);
            const html = await response.text();
            if (mine !== request) return;
            content.innerHTML = html;
            loaded = true;
            if (focused !== null) {
                const same = [...content.querySelectorAll('[data-peek-url]')].find((button) => (button.getAttribute('aria-label') ?? button.textContent) === focused);
                (same ?? panel.querySelector('[data-peek-close]')).focus();
            }
        } catch {
            if (mine !== request) return;
            content.innerHTML = '<p class="m-auto px-6 text-center text-sm text-coral-700">Couldn\'t load the schedule. Close and open it again.</p>';
            loaded = false;
        } finally {
            if (mine === request) content.removeAttribute('aria-busy');
        }
    }

    function open() {
        panel.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
        if (!loaded) load(`${root.dataset.url}?view=${remembered()}`);
        panel.querySelector('[data-peek-close]').focus();
    }

    function close() {
        panel.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
        toggle.focus();
    }

    toggle.addEventListener('click', () => (panel.hidden ? open() : close()));
    panel.querySelector('[data-peek-close]').addEventListener('click', close);
    // Esc closes it from anywhere on the page, unless a pop-up over the page takes the key.
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !panel.hidden && !document.querySelector('dialog[open]')) close();
    });
    content.addEventListener('click', (event) => {
        const button = event.target.closest('[data-peek-url]');
        if (!button) return;
        if (button.dataset.peekView) remember(button.dataset.peekView);
        load(button.dataset.peekUrl);
    });
}

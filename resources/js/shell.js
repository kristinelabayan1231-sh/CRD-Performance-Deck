// The app shell (components/layouts/app.blade.php): each rail button tells screen readers whether its module's flyout is
// open, and "Search screens" filters the pages the user can open (the flyouts' links) as they type.
export function initShell() {
    document.querySelectorAll('.rail-fly[popover]').forEach((fly) => {
        const button = document.querySelector(`[popovertarget="${fly.id}"]`);
        fly.addEventListener('toggle', (event) => button?.setAttribute('aria-expanded', String(event.newState === 'open')));
    });

    const form = document.querySelector('[data-screen-search]');
    if (!form) {
        return;
    }
    const input = form.querySelector('[data-screen-search-input]');
    const results = form.querySelector('[data-screen-results]');
    const screens = [...document.querySelectorAll('.rail-fly')].flatMap((fly) => {
        const module = fly.getAttribute('aria-label');

        return [...fly.querySelectorAll('a[href]')].map((link) => ({ name: link.textContent.trim(), module, href: link.href }));
    });

    const show = () => {
        const query = input.value.trim().toLowerCase();
        const found = screens.filter((screen) => `${screen.name} ${screen.module}`.toLowerCase().includes(query)).slice(0, 9);
        results.replaceChildren(
            ...(found.length
                ? found.map((screen) => {
                      const link = document.createElement('a');
                      link.href = screen.href;
                      link.className = 'flex items-center justify-between gap-3 rounded-xl px-3 py-2 text-sm font-medium hover:bg-canvas';
                      link.append(screen.name);
                      const module = document.createElement('small');
                      module.className = 'text-xs text-muted';
                      module.textContent = screen.module;
                      link.append(module);

                      return link;
                  })
                : [Object.assign(document.createElement('p'), { className: 'px-3 py-2 text-sm text-muted', textContent: 'No match. Try a screen name.' })]),
        );
        results.hidden = false;
    };

    input.addEventListener('input', show);
    input.addEventListener('focus', show);
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            results.hidden = true;
            input.blur();
        }
    });
    document.addEventListener('click', (event) => {
        if (!form.contains(event.target)) {
            results.hidden = true;
        }
    });
}

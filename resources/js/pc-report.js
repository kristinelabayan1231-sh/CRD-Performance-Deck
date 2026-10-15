// PC Report (preview: nothing there is saved yet; changes stay on the screen) and Settings → Product groups (saved with Save groups).
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

const label = (key) => `${MONTHS[Number(key.slice(5)) - 1]} ${key.slice(0, 4)}`;

// '2026-03'–'2026-08' → "Mar – Aug 2026"; across years "Nov 2025 – Feb 2026"; one month "Apr 2026".
function formatRange(from, to) {
    if (!from) return '';
    if (!to || to === from) return label(from);
    if (from.slice(0, 4) === to.slice(0, 4)) return `${MONTHS[Number(from.slice(5)) - 1]} – ${label(to)}`;
    return `${label(from)} – ${label(to)}`;
}

// Tagging per page, and the month range (set by the CRA Supervisor) a CRA's page pulls inquiries and engagement from.
export function initPcReport() {
    // Tagging is always caps ("SW1, SJJ"); keep the caret where it was while typing.
    document.addEventListener('input', (event) => {
        const input = event.target.closest('[data-pc-tagging]');
        if (!input) return;
        const { selectionStart, selectionEnd } = input;
        input.value = input.value.toUpperCase();
        input.setSelectionRange(selectionStart, selectionEnd);
    });

    const dialog = document.getElementById('pc-window-dialog');
    if (!dialog) return;

    initPageSplit(dialog.dataset.month);

    let target = null;
    let year = Number(dialog.dataset.year);
    let from = '';
    let to = '';

    const draw = () => {
        dialog.querySelector('[data-pc-year-label]').textContent = year;
        dialog.querySelectorAll('[data-pc-month]').forEach((button) => {
            const key = `${year}-${button.dataset.pcMonth.padStart(2, '0')}`;
            button.setAttribute('aria-pressed', key === from || key === to);
            button.toggleAttribute('data-range', Boolean(from && to && key > from && key < to));
        });
        dialog.querySelector('[data-pc-window-summary]').textContent = formatRange(from, to) || 'None';
    };

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-pc-window]');
        if (!button) return;

        target = button;
        from = button.dataset.from;
        to = button.dataset.to;
        year = from ? Number(from.slice(0, 4)) : Number(dialog.dataset.year);
        dialog.querySelector('[data-pc-window-for]').textContent = `${button.dataset.cra} · ${button.dataset.page}`;
        draw();
        dialog.showModal();
    });

    dialog.addEventListener('click', (event) => {
        // Click on the backdrop closes it.
        if (event.target === dialog) return dialog.close();

        const button = event.target.closest('button');
        if (!button) return;

        if (button.dataset.pcMonth) {
            // First click starts a range, the second ends it (either order); a third starts over.
            const key = `${year}-${button.dataset.pcMonth.padStart(2, '0')}`;
            if (!from || to) {
                from = key;
                to = '';
            } else {
                [from, to] = key < from ? [key, from] : [from, key];
            }
        } else if (button.dataset.pcYear) {
            year += Number(button.dataset.pcYear);
        } else if (button.hasAttribute('data-pc-clear')) {
            from = to = '';
        } else if (button.hasAttribute('data-pc-cancel')) {
            return dialog.close();
        } else if (button.hasAttribute('data-pc-save')) {
            target.dataset.from = from;
            target.dataset.to = from ? to || from : '';
            target.querySelector('[data-pc-window-label]').textContent = formatRange(from, to) || 'Not set';
            target.toggleAttribute('data-set', Boolean(from));
            return dialog.close();
        }
        draw();
    });
}

// A page's conversations by when they were created/updated: Dormant May 2024 – Dec 2025, Active 2026 onwards.
const CATEGORIES = [
    { name: 'DORMANT', from: '2024-05', to: () => '2025-12' },
    { name: 'ACTIVE', from: '2026-01', to: (thisMonth) => thisMonth },
];

// The CRA Supervisor's + splits a page row into a Dormant and an Active row; − on the Dormant row puts it back.
function initPageSplit(thisMonth) {
    const splitRow = (row, { name, from, to }, first) => {
        const copy = row.cloneNode(true);
        const page = `${row.dataset.page} - ${name}`;
        copy.hidden = false;
        copy.dataset.pcSplitOf = row.dataset.page;
        copy.querySelector('[data-pc-category]').textContent = ` - ${name}`;

        const toggle = copy.querySelector('[data-pc-split]');
        if (first) {
            delete toggle.dataset.pcSplit;
            toggle.dataset.pcMerge = '';
            toggle.textContent = '−';
            toggle.title = 'Put Dormant and Active back together';
            toggle.setAttribute('aria-label', `Put ${row.dataset.page} back into one row`);
        } else {
            toggle.replaceWith(Object.assign(document.createElement('span'), { className: 'size-5 shrink-0' }));
        }

        const tagging = copy.querySelector('[data-pc-tagging]');
        tagging.value = '';
        tagging.setAttribute('aria-label', tagging.getAttribute('aria-label').replace(row.dataset.page, page));

        const time = copy.querySelector('[data-pc-window]');
        time.dataset.page = page;
        time.dataset.from = from;
        time.dataset.to = to(thisMonth);
        time.setAttribute('aria-label', time.getAttribute('aria-label').replace(row.dataset.page, page));
        time.querySelector('[data-pc-window-label]').textContent = formatRange(time.dataset.from, time.dataset.to);
        time.toggleAttribute('data-set', true);

        return copy;
    };

    document.addEventListener('click', (event) => {
        const split = event.target.closest('[data-pc-split]');
        if (split) {
            const row = split.closest('[data-pc-row]');
            row.after(...CATEGORIES.map((category, i) => splitRow(row, category, i === 0)));
            row.hidden = true;
            return;
        }

        const merge = event.target.closest('[data-pc-merge]');
        if (merge) {
            const row = merge.closest('[data-pc-row]');
            const tbody = row.parentElement;
            tbody.querySelectorAll(`[data-pc-split-of="${CSS.escape(row.dataset.pcSplitOf)}"]`).forEach((copy) => copy.remove());
            tbody.querySelector(`[data-pc-row]:not([data-pc-split-of])[data-page="${CSS.escape(row.dataset.pcSplitOf)}"]`).hidden = false;
        }
    });
}

// Settings → Pancake Pages: group the pages that sell the same product; Save groups sends them.
export function initProductGroups() {
    const root = document.querySelector('[data-product-groups]');
    if (!root) return;

    const list = root.querySelector('[data-group-list]');
    const free = root.querySelector('[data-group-free]');
    const template = root.querySelector('[data-group-template]');
    const save = root.querySelector('[data-group-save]');
    const changed = () => root.querySelectorAll('[data-group-dirty]').forEach((note) => (note.hidden = false));

    const chip = (page, group) => {
        const span = document.createElement('span');
        span.dataset.page = page;
        span.className = 'inline-flex items-center gap-1 rounded-full border border-line bg-canvas/60 py-0.5 pl-3 text-xs font-medium ' + (group ? 'pr-1' : 'pr-3');
        span.textContent = page;
        if (group) {
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.dataset.groupUnpage = '';
            remove.className = 'grid size-5 place-items-center rounded-full text-muted hover:bg-coral/15 hover:text-coral-700';
            remove.setAttribute('aria-label', `Take ${page} out of ${group}`);
            remove.textContent = '×';
            span.append(remove);
        }
        return span;
    };

    // Counts, "no pages" notes and every group's page picker follow the chips on screen.
    const refresh = () => {
        const freePages = [...free.querySelectorAll('[data-page]')].map((el) => el.dataset.page);
        root.querySelectorAll('[data-group-list] [data-group]').forEach((group) => {
            const count = group.querySelectorAll('[data-group-pages] [data-page]').length;
            group.querySelector('[data-group-count]').textContent = count;
            group.querySelector('[data-group-rows]').textContent = count === 1 ? 'row' : 'rows';
            group.querySelector('[data-group-none]').classList.toggle('hidden', count > 0);
            const select = group.querySelector('[data-group-page]');
            select.replaceChildren(new Option(freePages.length ? '+ Add page' : 'No pages left', ''), ...freePages.map((page) => new Option(page, page)));
        });
        root.querySelector('[data-group-empty]').classList.toggle('hidden', list.children.length > 0);
        root.querySelector('[data-group-free-empty]').classList.toggle('hidden', freePages.length > 0);
    };

    root.querySelector('[data-group-add]').addEventListener('submit', (event) => {
        event.preventDefault();
        const input = root.querySelector('[data-group-name]');
        const name = input.value.trim();
        if (!name) return;

        const group = template.content.firstElementChild.cloneNode(true);
        group.querySelector('[data-group-title]').textContent = name;
        list.append(group);
        input.value = '';
        changed();
        refresh();
    });

    root.addEventListener('click', (event) => {
        const unpage = event.target.closest('[data-group-unpage]');
        if (unpage) {
            const page = unpage.closest('[data-page]');
            free.append(chip(page.dataset.page));
            page.remove();
            changed();
            return refresh();
        }

        const remove = event.target.closest('[data-group-remove]');
        if (remove) {
            const group = remove.closest('[data-group]');
            group.querySelectorAll('[data-group-pages] [data-page]').forEach((page) => free.append(chip(page.dataset.page)));
            group.remove();
            changed();
            refresh();
        }
    });

    root.addEventListener('change', (event) => {
        const select = event.target.closest('[data-group-page]');
        if (!select?.value) return;

        const group = select.closest('[data-group]');
        group.querySelector('[data-group-none]').before(chip(select.value, group.querySelector('[data-group-title]').textContent));
        free.querySelector(`[data-page="${CSS.escape(select.value)}"]`)?.remove();
        changed();
        refresh();
    });

    // The groups on screen, as the server saves them: a name and its pages each.
    save?.addEventListener('submit', () => {
        save.querySelector('[data-group-json]').value = JSON.stringify(
            [...list.querySelectorAll('[data-group]')].map((group) => ({
                name: group.querySelector('[data-group-title]').textContent.trim(),
                pages: [...group.querySelectorAll('[data-group-pages] [data-page]')].map((page) => page.dataset.page),
            })),
        );
    });

    refresh();
}

// PC Report (Total Inquiries and Total Engagement per day, Tagging, Creation/Update Time and Dormant/Active rows are saved) and Settings → Product groups (saved with Save groups).
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

const label = (key) => `${MONTHS[Number(key.slice(5)) - 1]} ${key.slice(0, 4)}`;

// '2026-03'–'2026-08' → "Mar – Aug 2026"; across years "Nov 2025 – Feb 2026"; one month "Apr 2026".
function formatRange(from, to) {
    if (!from) return '';
    if (!to || to === from) return label(from);
    if (from.slice(0, 4) === to.slice(0, 4)) return `${MONTHS[Number(from.slice(5)) - 1]} – ${label(to)}`;
    return `${label(from)} – ${label(to)}`;
}

// Save a PC Report row's counts, Tagging or Creation/Update Time, or split / merge a page; resolves when the server took it.
async function send(url, method, body) {
    const response = await fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
        },
        body: JSON.stringify(body),
    });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
}

// Total Inquiries and Total Engagement, typed per row for the day picked: saved when the box changes (empty clears it),
// and the set's TOTAL adds up what's in its boxes (# of Orders and Amount come from the CVR Tracker). Conversion Rate = # of Orders ÷ Total Engagement, per page
// (a split page's two rows together) and in all, worked out again as engagement is typed.
function initPcCounts(root) {
    const dash = '—';
    const filled = (inputs) => [...inputs].filter((input) => input.value !== '').map(Number);
    const sum = (values) => values.reduce((a, b) => a + b, 0);
    const percent = (orders, engagements) => (engagements > 0 ? `${((orders / engagements) * 100).toFixed(2)}%` : dash);

    const total = (table, field) => {
        const values = filled(table.querySelectorAll(`[data-pc-count="${field}"]`));
        table.querySelector(`[data-pc-totals] [data-pc-total="${field}"]`).textContent = values.length ? sum(values).toLocaleString('en-US') : dash;
    };

    const conversion = (table) => {
        const engagementsOf = (page) => sum(filled([...table.querySelectorAll('[data-pc-count="engagements"]')].filter((input) => input.dataset.page === page)));
        let orders = 0;
        let counted = false;
        table.querySelectorAll('[data-pc-orders]').forEach((cell) => {
            if (cell.dataset.pcOrders === '') return;
            const page = cell.dataset.page;
            const pageOrders = Number(cell.dataset.pcOrders);
            orders += pageOrders;
            counted = true;
            const rate = table.querySelector(`[data-pc-conversion][data-page="${CSS.escape(page)}"]`);
            if (rate) rate.textContent = percent(pageOrders, engagementsOf(page));
        });
        const all = sum(filled(table.querySelectorAll('[data-pc-count="engagements"]')));
        table.querySelector('[data-pc-totals] [data-pc-total="conversion"]').textContent = counted ? percent(orders, all) : dash;
    };

    root.addEventListener('change', async (event) => {
        const input = event.target.closest('[data-pc-count]');
        if (!input) return;
        const { cra, page, category, pcCount: field } = input.dataset;
        const number = Math.max(0, Number(input.value));
        const value = input.value === '' ? null : Math.floor(number);
        if (value !== null) input.value = value;
        const table = input.closest('table');
        total(table, field);
        if (field === 'engagements') conversion(table);
        try {
            await send(root.dataset.entriesUrl, 'PUT', { cra, page, category, date: root.dataset.date, [field]: value });
            input.classList.remove('border-coral');
            input.title = '';
        } catch {
            input.classList.add('border-coral');
            input.title = "Couldn't save; try again.";
        }
    });
}

// Tagging per page (saved when the box loses focus), the month range set by the CRA Supervisor,
// and the Supervisor's + / − that split a page into Dormant and Active rows and back.
export function initPcReport() {
    const root = document.querySelector('[data-pc-report]');
    if (!root) return;

    // Tagging is always caps ("SW1, SJJ"); keep the caret where it was while typing.
    root.addEventListener('input', (event) => {
        const input = event.target.closest('[data-pc-tagging]');
        if (!input) return;
        const { selectionStart, selectionEnd } = input;
        input.value = input.value.toUpperCase();
        input.setSelectionRange(selectionStart, selectionEnd);
    });

    root.addEventListener('change', async (event) => {
        const input = event.target.closest('[data-pc-tagging]');
        if (!input) return;
        const { cra, page, category } = input.dataset;
        try {
            await send(root.dataset.rowsUrl, 'PUT', { cra, page, category, tagging: input.value.trim() });
            input.classList.remove('border-coral');
        } catch {
            input.classList.add('border-coral');
            input.title = "Couldn't save; try again.";
        }
    });

    initPcCounts(root);

    const dialog = document.getElementById('pc-window-dialog');
    if (!dialog) return;

    root.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-pc-split], [data-pc-merge]');
        if (!button) return;
        button.disabled = true;
        try {
            await send(dialog.dataset.splitUrl, button.hasAttribute('data-pc-split') ? 'POST' : 'DELETE', { cra: button.dataset.cra, page: button.dataset.page, date: root.dataset.date });
            window.location.reload();
        } catch {
            button.disabled = false;
        }
    });

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
        dialog.querySelector('[data-pc-window-for]').textContent = `${button.dataset.cra} · ${button.dataset.page}${button.dataset.category ? ` - ${button.dataset.category}` : ''}`;
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
            // Saved for the day picked only, then the page reloads with the new months.
            const { craId: cra, page, category } = target.dataset;
            button.disabled = true;
            send(dialog.dataset.url, 'PUT', { cra, page, category, date: root.dataset.date, from: from || null, to: from ? to || from : null })
                .then(() => window.location.reload())
                .catch(() => {
                    button.disabled = false;
                    dialog.querySelector('[data-pc-window-summary]').textContent = "Couldn't save; try again.";
                });
            return;
        }
        draw();
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

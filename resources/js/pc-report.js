// PC Report (Tagging, Creation/Update Time and Dormant/Active rows are saved) and Settings → Product groups (saved with Save groups).
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

const label = (key) => `${MONTHS[Number(key.slice(5)) - 1]} ${key.slice(0, 4)}`;

// '2026-03'–'2026-08' → "Mar – Aug 2026"; across years "Nov 2025 – Feb 2026"; one month "Apr 2026".
function formatRange(from, to) {
    if (!from) return '';
    if (!to || to === from) return label(from);
    if (from.slice(0, 4) === to.slice(0, 4)) return `${MONTHS[Number(from.slice(5)) - 1]} – ${label(to)}`;
    return `${label(from)} – ${label(to)}`;
}

// Save a PC Report row's Tagging or Creation/Update Time, or split / merge a page; resolves when the server took it.
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

// A row's Sync: starts the background fetch of its inquiries and engagements, then follows it with the progress bar
// (asking every 2 seconds) and reloads the page when it's done, so the counts show. A row already syncing when the
// page opens is followed too.
function initPcSync(root) {
    const show = (box, progress) => {
        const running = progress.status === 'starting' || progress.status === 'running';
        const percent = progress.total ? Math.floor((progress.done / progress.total) * 100) : 0;
        box.querySelector('[data-pc-sync-running]').classList.toggle('hidden', !running);
        box.querySelector('[data-pc-sync-idle]').classList.toggle('hidden', running);
        box.querySelector('[data-pc-sync-bar]').style.width = `${percent}%`;
        box.querySelector('[data-pc-sync-meter]').setAttribute('aria-valuenow', percent);
        box.querySelector('[data-pc-sync-text]').textContent = `Syncing… ${progress.done ?? 0} / ${progress.total ?? 0} days · ${percent}%`;
        const note = box.querySelector('[data-pc-sync-note]');
        if (progress.message) {
            note.innerHTML = '';
            note.append(Object.assign(document.createElement('span'), { className: 'text-coral-700', textContent: progress.message }));
        } else if (running) {
            note.textContent = '';
        }
    };

    const follow = (box) => {
        const tick = async () => {
            try {
                const response = await fetch(box.dataset.progressUrl, { headers: { Accept: 'application/json' } });
                const progress = await response.json();
                show(box, progress);
                if (progress.status === 'done') return window.location.reload();
                if (progress.status === 'starting' || progress.status === 'running') return setTimeout(tick, 2000);
            } catch {
                setTimeout(tick, 5000);
            }
            box.querySelector('[data-pc-sync-start]').disabled = false;
        };
        tick();
    };

    root.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-pc-sync-start]');
        if (!button) return;
        const box = button.closest('[data-pc-sync]');
        button.disabled = true;
        try {
            const response = await fetch(root.dataset.syncUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                },
                body: JSON.stringify({ cra: box.dataset.cra, page: box.dataset.page, category: box.dataset.category }),
            });
            const progress = await response.json();
            show(box, progress.status ? progress : { status: 'failed', message: progress.message || "Couldn't start the sync." });
            if (response.ok) follow(box);
            else button.disabled = false;
        } catch {
            show(box, { status: 'failed', message: "Couldn't start the sync; try again." });
            button.disabled = false;
        }
    });

    root.querySelectorAll('[data-pc-sync][data-status="starting"], [data-pc-sync][data-status="running"]').forEach(follow);
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

    initPcSync(root);

    const dialog = document.getElementById('pc-window-dialog');
    if (!dialog) return;

    root.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-pc-split], [data-pc-merge]');
        if (!button) return;
        button.disabled = true;
        try {
            await send(dialog.dataset.splitUrl, button.hasAttribute('data-pc-split') ? 'POST' : 'DELETE', { cra: button.dataset.cra, page: button.dataset.page });
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
            // Saved, then the page reloads so Total Inquiries counts the new months.
            const { craId: cra, page, category } = target.dataset;
            button.disabled = true;
            send(dialog.dataset.url, 'PUT', { cra, page, category, from: from || null, to: from ? to || from : null })
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

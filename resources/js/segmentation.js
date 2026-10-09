// Segmentation Tracker: auto-saving cells, hide/unhide columns, Sheets-style notes.

const COLUMNS_KEY = 'segmentation.hiddenColumns';

function readHidden() {
    try {
        return JSON.parse(localStorage.getItem(COLUMNS_KEY)) || [];
    } catch {
        return [];
    }
}

function writeHidden(keys) {
    try {
        localStorage.setItem(COLUMNS_KEY, JSON.stringify(keys));
    } catch {
        // Storage unavailable (private mode): the choice just won't persist.
    }
}

function applyHidden(keys) {
    let style = document.getElementById('segmentation-hidden-columns');
    if (!style) {
        style = document.createElement('style');
        style.id = 'segmentation-hidden-columns';
        document.head.append(style);
    }
    style.textContent = keys.map((key) => `[data-col="${CSS.escape(key)}"]{display:none}`).join('\n');

    document.querySelectorAll('[data-column-toggle]').forEach((box) => {
        if (!box.disabled) box.checked = !keys.includes(box.dataset.columnToggle);
    });
}

function initColumns() {
    // Hidden columns also apply in the full-view pop-up, which has no picker.
    applyHidden(readHidden());

    const picker = document.querySelector('[data-column-picker]');
    if (!picker) return;

    picker.addEventListener('change', (event) => {
        const box = event.target.closest('[data-column-toggle]');
        if (!box) return;
        const hidden = new Set(readHidden());
        box.checked ? hidden.delete(box.dataset.columnToggle) : hidden.add(box.dataset.columnToggle);
        writeHidden([...hidden]);
        applyHidden([...hidden]);
    });

    picker.querySelectorAll('[data-columns-all]').forEach((button) =>
        button.addEventListener('click', () => {
            const keys = button.dataset.columnsAll === 'hide'
                ? [...picker.querySelectorAll('[data-column-toggle]:not(:disabled)')].map((box) => box.dataset.columnToggle)
                : [];
            writeHidden(keys);
            applyHidden(keys);
        }),
    );

    // Close the picker when clicking elsewhere.
    document.addEventListener('click', (event) => {
        if (picker.open && !picker.contains(event.target)) picker.open = false;
    });
}

async function send(url, formData) {
    const response = await fetch(url, {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: formData,
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) {
        const firstError = body.errors ? Object.values(body.errors)[0][0] : null;
        throw new Error(firstError || body.message || `Save failed (HTTP ${response.status}).`);
    }
    return body;
}

function flash(element, ok, message = '') {
    const ring = ok ? 'ring-teal' : 'ring-coral';
    element.classList.add('ring-2', ring);
    element.title = message;
    setTimeout(() => element.classList.remove('ring-2', ring), ok ? 900 : 4000);
}

function recolor(select) {
    if (!select.dataset.colors) return;
    const colors = JSON.parse(select.dataset.colors);
    const all = [...Object.values(colors), select.dataset.emptyClasses].join(' ').split(/\s+/).filter(Boolean);
    select.classList.remove(...all);
    select.classList.add(...(colors[select.value] || select.dataset.emptyClasses).split(/\s+/).filter(Boolean));
}

function initAutosave() {
    // Enter in a text field saves via the change event instead of a full form submit.
    document.addEventListener('submit', (event) => {
        if (!event.target.matches('form[data-autosave]')) return;
        event.preventDefault();
        event.target.querySelector('input:not([type=hidden]), select')?.blur();
    });

    document.addEventListener('change', async (event) => {
        const form = event.target.closest('form[data-autosave]');
        if (!form) return;

        const field = event.target;
        const previous = field.dataset.saved ?? field.defaultValue ?? '';
        recolor(field);

        try {
            const result = await send(form.action, new FormData(form));
            field.dataset.saved = field.value;
            flash(field, true);
            if (['status', 'assigned_to', 'repeat_purchase', 'feedback'].includes(field.name)) refreshSummary();
            // A status can set the feedback too (PJR → NO VERBAL CONV): show it in the same row.
            const feedback = field.name === 'status' && field.closest('tr')?.querySelector('select[name=feedback]');
            if (feedback && result.feedback !== undefined && feedback.value !== (result.feedback ?? '')) {
                feedback.value = result.feedback ?? '';
                feedback.dataset.saved = feedback.value;
                recolor(feedback);
                flash(feedback, true);
            }
            // Optionally mirror a returned value elsewhere on the page (e.g. a renamed user).
            if (form.dataset.updateText && result.display_name !== undefined) {
                document.querySelectorAll(form.dataset.updateText).forEach((el) => (el.textContent = result.display_name));
            }
        } catch (error) {
            flash(field, false, error.message);
            alert(error.message);
            if (field.tagName === 'SELECT') {
                field.value = field.dataset.saved ?? [...field.options].find((o) => o.defaultSelected)?.value ?? '';
                recolor(field);
            } else {
                field.value = previous;
            }
        }
    });
}

// Summary tiles: refresh after a status change and every 30 seconds.
let refreshSummary = () => {};

function initLiveSummary() {
    const container = document.querySelector('[data-live-summary]');
    if (!container) return;

    refreshSummary = async () => {
        try {
            const response = await fetch(container.dataset.url, { headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const tiles = await response.json();
            for (const [key, tile] of Object.entries(tiles)) {
                const value = container.querySelector(`[data-tile-value="${key}"]`);
                const note = container.querySelector(`[data-tile-note="${key}"]`);
                if (value && value.textContent !== tile.value) {
                    value.textContent = tile.value;
                    value.animate([{ opacity: 0.4 }, { opacity: 1 }], { duration: 400 });
                }
                if (note) note.textContent = tile.note ?? '';
            }
            if (tiles.per_cra) renderPerCra(tiles.per_cra);
        } catch {
            // Offline or server busy: try again on the next tick.
        }
    };

    setInterval(() => document.visibilityState === 'visible' && refreshSummary(), 30000);
    document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && refreshSummary());
}

// Per CRA pop-up: opened from the Per CRA tile, re-rendered on every summary refresh.
function renderPerCra(tile) {
    const dialog = document.getElementById('per-cra-dialog');
    if (!dialog || !tile.breakdown) return;

    dialog.querySelector('[data-per-cra-period]').textContent = tile.period ?? '';
    dialog.querySelector('[data-per-cra-total]').textContent = tile.breakdown.reduce((sum, row) => sum + row.total, 0);

    const list = dialog.querySelector('[data-per-cra-list]');
    list.replaceChildren(...(tile.breakdown.length ? tile.breakdown.map((row) => {
        const li = document.createElement('li');
        li.className = 'flex items-center justify-between gap-4 px-5 py-3' + (row.odd ? ' bg-coral/10' : '');

        const info = document.createElement('div');
        info.className = 'min-w-0';
        const name = document.createElement('p');
        name.className = 'truncate font-medium';
        name.textContent = row.name;
        const split = document.createElement('p');
        split.className = 'text-xs text-muted';
        split.textContent = `${row.crd} CRD · ${row.fsd} FSD`;
        info.append(name, split);

        const right = document.createElement('div');
        right.className = 'flex items-center gap-2';
        if (row.odd) {
            const badge = document.createElement('span');
            badge.className = 'rounded-full bg-coral/20 px-2 py-0.5 text-xs font-semibold text-coral-700';
            badge.textContent = 'Uneven';
            right.append(badge);
        }
        const count = document.createElement('span');
        count.className = 'text-2xl font-bold tabular-nums';
        count.textContent = row.total;
        right.append(count);

        li.append(info, right);
        return li;
    }) : [Object.assign(document.createElement('li'), { className: 'px-5 py-6 text-center text-sm text-muted', textContent: 'No CRAs yet.' })]));
}

function initPerCra() {
    const dialog = document.getElementById('per-cra-dialog');
    const open = document.querySelector('[data-per-cra-open]');
    if (!dialog || !open) return;

    open.addEventListener('click', () => {
        dialog.showModal();
        refreshSummary();
    });
    dialog.querySelector('[data-per-cra-close]').addEventListener('click', () => dialog.close());
    // Click on the backdrop closes it.
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });
}

// Backlog transfer: pick a CRA (with their current load), then confirm.
function initTransfer() {
    const dialog = document.getElementById('transfer-dialog');
    if (!dialog) return;

    const form = dialog.querySelector('[data-transfer-form]');
    const workload = JSON.parse(dialog.dataset.workload || '[]');
    const choose = dialog.querySelector('[data-transfer-step="choose"]');
    const confirm = dialog.querySelector('[data-transfer-step="confirm"]');
    let pending = null;

    const loadText = (cra) => `${cra.today} today · ${cra.backlog} backlog`;

    const open = ({ ids, fromIds, fromName, label }) => {
        pending = { ids, fromIds, fromName, label };
        dialog.querySelector('[data-transfer-ids]').replaceChildren(...ids.map((id) =>
            Object.assign(document.createElement('input'), { type: 'hidden', name: 'lead_ids[]', value: id })));
        form.elements.to.value = '';
        dialog.querySelector('[data-transfer-summary]').textContent = `${label} · currently with ${fromName}`;

        const targets = workload.filter((cra) => !(fromIds.length === 1 && String(cra.id) === String(fromIds[0])));
        dialog.querySelector('[data-transfer-targets]').replaceChildren(...(targets.length ? targets.map((cra) => {
            const li = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'flex w-full items-center justify-between gap-4 px-5 py-3 text-left hover:bg-brand-50 focus-visible:bg-brand-50 focus-visible:outline-none';
            const name = Object.assign(document.createElement('span'), { className: 'font-medium', textContent: cra.name });
            const load = Object.assign(document.createElement('span'), { className: 'text-sm tabular-nums text-muted', textContent: loadText(cra) });
            button.append(name, load);
            button.addEventListener('click', () => pick(cra));
            li.append(button);
            return li;
        }) : [Object.assign(document.createElement('li'), { className: 'px-5 py-4 text-sm text-muted', textContent: 'No other active CRAs to transfer to.' })]));

        choose.hidden = false;
        confirm.hidden = true;
        dialog.showModal();
    };

    const pick = (cra) => {
        const n = pending.ids.length;
        form.elements.to.value = cra.id;
        dialog.querySelector('[data-transfer-confirm-text]').textContent =
            `Transfer ${n === 1 ? pending.label : `${n} leads`} from ${pending.fromName} to ${cra.name}?`;
        dialog.querySelector('[data-transfer-after]').textContent =
            `${cra.name} currently has ${loadText(cra)}. After this: ${cra.backlog + n} backlog.`;
        choose.hidden = true;
        confirm.hidden = false;
        confirm.querySelector('[type=submit]').focus();
    };

    document.addEventListener('click', (event) => {
        const single = event.target.closest('[data-transfer-open]');
        if (single) {
            open({
                ids: JSON.parse(single.dataset.leadIds),
                fromIds: [single.dataset.from],
                fromName: single.dataset.fromName,
                label: single.dataset.label,
            });
            return;
        }

        const bulk = event.target.closest('[data-transfer-selected]');
        if (bulk) {
            const boxes = [...document.querySelectorAll('[data-backlog-select]:checked')];
            if (!boxes.length) return;
            const fromIds = [...new Set(boxes.map((box) => box.dataset.from))];
            const fromNames = [...new Set(boxes.map((box) => box.dataset.fromName))];
            open({
                ids: boxes.map((box) => box.value),
                fromIds,
                fromName: fromNames.join(', '),
                label: `${boxes.length} selected ${boxes.length === 1 ? 'lead' : 'leads'}`,
            });
        }
    });

    // Enable "Transfer selected" only when something is ticked.
    document.addEventListener('change', (event) => {
        if (!event.target.matches('[data-backlog-select]')) return;
        const count = document.querySelectorAll('[data-backlog-select]:checked').length;
        document.querySelectorAll('[data-transfer-selected]').forEach((button) => {
            button.disabled = count === 0;
            button.textContent = count ? `Transfer selected (${count})` : 'Transfer selected';
        });
    });

    dialog.querySelector('[data-transfer-back]').addEventListener('click', () => {
        choose.hidden = false;
        confirm.hidden = true;
    });
    dialog.querySelector('[data-transfer-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });
}

function initNotes() {
    const dialog = document.getElementById('note-dialog');
    if (!dialog) return;

    const form = dialog.querySelector('[data-note-form]');
    const textarea = form.elements.notes;
    const error = dialog.querySelector('[data-note-error]');
    let cell = null;

    const noteOf = (td) => td.querySelector('[data-note-value]').content.textContent.trim();

    const render = (td, notes, meta) => {
        td.querySelector('[data-note-value]').content.textContent = notes || '';
        td.querySelector('[data-note-indicator]').hidden = !notes;
        td.querySelector('[data-note-popover]').hidden = !notes;
        td.querySelector('[data-note-text]').textContent = notes || '';
        td.querySelector('[data-note-meta]').textContent = meta || '';
    };

    const save = async (notes) => {
        const data = new FormData();
        data.append('_method', 'PATCH');
        data.append('_token', document.querySelector('meta[name=csrf-token]')?.content || '');
        data.append('notes', notes);

        error.classList.add('hidden');
        try {
            const result = await send(cell.dataset.noteUrl, data);
            render(cell, result.notes, result.notes_meta);
            dialog.close();
        } catch (e) {
            error.textContent = e.message;
            error.classList.remove('hidden');
        }
    };

    document.addEventListener('click', (event) => {
        const open = event.target.closest('[data-note-open]');
        if (!open) return;
        cell = open.closest('[data-note-cell]');
        textarea.value = noteOf(cell);
        dialog.querySelector('[data-note-title]').textContent = cell.dataset.customer;
        dialog.querySelector('[data-note-delete]').hidden = !textarea.value;
        error.classList.add('hidden');
        dialog.showModal();
        textarea.focus();
    });

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        save(textarea.value);
    });
    dialog.querySelector('[data-note-delete]').addEventListener('click', () => save(''));
    dialog.querySelector('[data-note-cancel]').addEventListener('click', () => dialog.close());
}

// Reload the Pending and Catered lists in place (pages, counts and order from the server).
async function refreshSections() {
    const response = await fetch(window.location.href, { headers: { Accept: 'text/html' } });
    if (!response.ok) throw new Error(`Couldn't reload the lists (HTTP ${response.status}).`);
    const page = new DOMParser().parseFromString(await response.text(), 'text/html');
    document.querySelectorAll('[data-lead-section]').forEach((section) => {
        const fresh = page.querySelector(`[data-lead-section="${CSS.escape(section.dataset.leadSection)}"]`);
        if (fresh) section.replaceWith(document.importNode(fresh, true));
    });
}

// Right-click a lead row: Mark as catered (Pending list) or Unmark catered (Catered list).
function initRowMenu() {
    const menu = document.createElement('div');
    menu.setAttribute('role', 'menu');
    menu.hidden = true;
    menu.className = 'fixed z-50 min-w-48 overflow-hidden rounded-lg border border-line bg-white py-1 text-sm shadow-lg';
    const item = document.createElement('button');
    item.type = 'button';
    item.setAttribute('role', 'menuitem');
    item.className = 'flex w-full items-center gap-2 px-3 py-2 text-left font-medium hover:bg-brand-50 focus-visible:bg-brand-50 focus-visible:outline-none';
    menu.append(item);
    document.body.append(menu);

    let row = null;
    const close = () => {
        menu.hidden = true;
        row = null;
    };

    document.addEventListener('contextmenu', (event) => {
        const target = event.target.closest('tr[data-lead-row]');
        // Rows the user can't edit (no data-lead-row) keep the browser's own menu.
        if (!target) return close();
        event.preventDefault();
        row = target;
        const toProcessed = row.dataset.mark === 'processed';
        item.innerHTML = `<span aria-hidden="true" class="size-2.5 rounded-full ${toProcessed ? 'bg-teal' : 'bg-coral'}"></span>`;
        item.append(toProcessed ? 'Mark as catered' : 'Unmark catered');
        menu.hidden = false;
        // At the pointer; from the keyboard (menu key), under the row's first cell.
        const at = event.clientX || event.clientY ? { x: event.clientX, y: event.clientY } : (() => {
            const rect = row.cells[0].getBoundingClientRect();
            return { x: rect.left + 16, y: rect.bottom };
        })();
        menu.style.left = `${Math.min(at.x, window.innerWidth - menu.offsetWidth - 8)}px`;
        menu.style.top = `${Math.min(at.y, window.innerHeight - menu.offsetHeight - 8)}px`;
        item.focus();
    });

    item.addEventListener('click', async () => {
        const target = row;
        if (!target) return;
        const processed = target.dataset.mark === 'processed';
        close();

        // Unmarking a lead with a status or date of contact clears them: confirm first.
        if (!processed && target.dataset.clears
            && !confirm(`Move ${target.dataset.customer} back to Pending? This clears its ${target.dataset.clears}.`)) return;

        const data = new FormData();
        data.append('_method', 'PATCH');
        data.append('_token', document.querySelector('meta[name=csrf-token]')?.content || '');
        data.append('processed', processed ? '1' : '0');

        target.style.transition = 'opacity 200ms';
        target.style.opacity = '0.35';
        try {
            await send(target.dataset.updateUrl, data);
            await refreshSections();
            refreshSummary();
        } catch (error) {
            target.style.opacity = '';
            alert(error.message);
        }
    });

    document.addEventListener('click', (event) => !menu.contains(event.target) && close());
    document.addEventListener('keydown', (event) => event.key === 'Escape' && close());
    window.addEventListener('scroll', close, true);
    window.addEventListener('resize', close);
}

// Expand: open a section's full list in a pop-up (an embedded page); refresh on close.
function initExpand() {
    const dialog = document.getElementById('expand-dialog');
    if (!dialog) return;
    const frame = dialog.querySelector('iframe');
    let opened = false;

    // Delegated: the lists are swapped in place after Mark as processed.
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-expand-src]');
        if (!button) return;
        dialog.querySelector('[data-expand-title]').textContent = button.dataset.expandTitle;
        frame.src = button.dataset.expandSrc;
        opened = true;
        dialog.showModal();
    });

    dialog.querySelector('[data-expand-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        frame.src = 'about:blank';
        // Changes made in the pop-up move customers between the lists.
        if (opened) window.location.reload();
    });
}

// A CRA's hello pop-up, opened on their first visit of the day.
function initGreeting() {
    const dialog = document.getElementById('greeting-dialog');
    if (!dialog) return;
    dialog.querySelector('[data-greeting-close]').addEventListener('click', () => dialog.close());
    dialog.showModal();
}

export function initSegmentation() {
    initExpand();
    initGreeting();
    if (document.querySelector('[data-lead-section]')) initRowMenu();
    if (!document.querySelector('[data-column-picker], form[data-autosave], [data-live-summary], #transfer-dialog')) return;
    initColumns();
    initLiveSummary();
    initPerCra();
    initTransfer();
    initAutosave();
    initNotes();
}

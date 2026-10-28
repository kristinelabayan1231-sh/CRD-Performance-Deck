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
            if (['status', 'assigned_to', 'repeat_purchase', 'feedbacks[]'].includes(field.name)) refreshSummary();
            if (field.name === 'feedbacks[]') renderFeedback(form, result.feedbacks);
            // A status can add a feedback too (PJR → NO VERBAL CONV): show it in the same row.
            const feedback = field.name === 'status' && field.closest('tr')?.querySelector('[data-feedback-form]');
            if (feedback && result.feedbacks !== undefined) {
                const before = checkedFeedbacks(feedback).join();
                feedback.querySelectorAll('input[name="feedbacks[]"]:not([value=""])').forEach((box) => (box.checked = result.feedbacks.includes(box.value)));
                renderFeedback(feedback, result.feedbacks);
                if (checkedFeedbacks(feedback).join() !== before) flash(feedback.querySelector('[popovertarget]'), true);
            }
            // A customer tag sets the Date and Time of contact: show them in the same row.
            if (field.name === 'customer_tag') {
                const row = field.closest('tr');
                [['input[name=contact_date]', result.contact_date], ['select[name=contact_time]', result.contact_time]].forEach(([selector, value]) => {
                    const cell = row?.querySelector(selector);
                    if (!cell || value === undefined || cell.value === (value ?? '')) return;
                    cell.value = value ?? '';
                    cell.dataset.saved = cell.value;
                    recolor(cell);
                    flash(cell, true);
                });
            }
            // Optionally mirror a returned value elsewhere on the page (e.g. a renamed user).
            if (form.dataset.updateText && result.display_name !== undefined) {
                document.querySelectorAll(form.dataset.updateText).forEach((el) => (el.textContent = result.display_name));
            }
        } catch (error) {
            flash(field, false, error.message);
            alert(error.message);
            if (field.type === 'checkbox') {
                field.checked = !field.checked;
                renderFeedback(form);
            } else if (field.tagName === 'SELECT') {
                field.value = field.dataset.saved ?? [...field.options].find((o) => o.defaultSelected)?.value ?? '';
                recolor(field);
            } else {
                field.value = previous;
            }
        }
    });
}

// Customer's Feedback cell: the ticked choices as badges on its button, in the saved order (main one first) when given.
function checkedFeedbacks(form) {
    return [...form.querySelectorAll('input[name="feedbacks[]"]:checked:not([value=""])')].map((box) => box.value);
}

function renderFeedback(form, order = null) {
    const boxes = [...form.querySelectorAll('input[name="feedbacks[]"]:checked:not([value=""])')];
    if (order) boxes.sort((a, b) => order.indexOf(a.value) - order.indexOf(b.value));
    const badges = boxes.map((box) => box.nextElementSibling.cloneNode(true));
    if (!badges.length) {
        const empty = document.createElement('span');
        empty.className = 'px-1 text-xs font-semibold text-muted';
        empty.textContent = '—';
        badges.push(empty);
    }
    form.querySelector('[data-feedback-badges]').replaceChildren(...badges);
}

// Its pop-up opens under the button (above it when there's no room below), kept inside the window.
function initFeedbackMenus() {
    // Placed before it shows (so it doesn't flash in the middle) and again once its size is known.
    const place = (event) => {
        const menu = event.target;
        if (!menu.matches?.('[data-feedback-menu]') || event.newState !== 'open') return;
        const button = menu.closest('form').querySelector('[popovertarget]');
        const box = button.getBoundingClientRect();
        const below = window.innerHeight - box.bottom;
        menu.style.position = 'fixed';
        menu.style.inset = 'auto';
        menu.style.left = `${Math.max(8, Math.min(box.left, window.innerWidth - menu.offsetWidth - 8))}px`;
        menu.style.top = below >= menu.offsetHeight + 8 || below >= box.top
            ? `${box.bottom + 4}px`
            : `${Math.max(8, box.top - menu.offsetHeight - 4)}px`;
    };
    document.addEventListener('beforetoggle', place, true);
    document.addEventListener('toggle', place, true);
    // The table scrolls under a fixed pop-up: close it rather than leave it floating.
    document.addEventListener('scroll', (event) => {
        if (event.target.closest?.('[data-feedback-menu]')) return;
        document.querySelectorAll('[data-feedback-menu]:popover-open').forEach((menu) => menu.hidePopover());
    }, true);
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

// Order icon beside a customer's name: hovering shows their order history (order date · product) in a
// floating box, fetched once per row and kept for the next hover.
function initOrderHistory() {
    const box = document.createElement('div');
    box.className = 'fixed z-50 w-72 rounded-md border border-line bg-white p-3 text-left text-xs shadow-lg';
    box.hidden = true;
    box.setAttribute('role', 'tooltip');
    document.body.appendChild(box);
    const cache = new Map();
    let current = null;

    const render = (orders) => {
        box.replaceChildren();
        const title = document.createElement('p');
        title.className = 'mb-1.5 font-semibold text-ink';
        title.textContent = 'Order history';
        box.appendChild(title);
        if (typeof orders === 'string' || orders.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'text-muted';
            empty.textContent = typeof orders === 'string' ? orders : 'No orders found.';
            box.appendChild(empty);
            return;
        }
        const list = document.createElement('ul');
        list.className = 'max-h-60 space-y-1 overflow-y-auto';
        orders.forEach((order) => {
            const item = document.createElement('li');
            item.className = 'flex gap-2';
            const date = document.createElement('span');
            date.className = 'shrink-0 text-muted tabular-nums';
            date.textContent = order.date;
            const product = document.createElement('span');
            product.className = 'text-ink';
            product.textContent = order.product;
            item.append(date, product);
            list.appendChild(item);
        });
        box.appendChild(list);
    };

    const place = (button) => {
        const rect = button.getBoundingClientRect();
        const { offsetWidth: w, offsetHeight: h } = box;
        const x = Math.min(rect.left, window.innerWidth - w - 8);
        const y = rect.bottom + 4 + h > window.innerHeight - 8 ? rect.top - h - 4 : rect.bottom + 4;
        box.style.left = `${Math.max(8, x)}px`;
        box.style.top = `${Math.max(8, y)}px`;
    };

    const notePopover = (button) => button.closest('[data-note-cell]')?.querySelector('[data-note-popover]');

    const show = async (button) => {
        current = button;
        const note = notePopover(button);
        if (note) note.style.display = 'none';
        const url = button.dataset.ordersUrl;
        if (!cache.has(url)) {
            render('Loading…');
            box.hidden = false;
            place(button);
            cache.set(url, fetch(url, { headers: { Accept: 'application/json' } })
                .then((response) => (response.ok ? response.json() : Promise.reject()))
                .then((data) => data.orders)
                .catch(() => {
                    cache.delete(url);
                    return "Couldn't load the orders.";
                }));
        }
        const orders = await cache.get(url);
        if (current !== button) return;
        render(orders);
        box.hidden = false;
        place(button);
    };

    const hide = (button) => {
        if (current === button) current = null;
        box.hidden = true;
        const note = notePopover(button);
        if (note) note.style.display = '';
    };

    ['mouseover', 'focusin'].forEach((type) => document.addEventListener(type, (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-orders-open]') : null;
        if (button && button !== current) show(button);
    }));
    ['mouseout', 'focusout'].forEach((type) => document.addEventListener(type, (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-orders-open]') : null;
        if (button && !button.contains(event.relatedTarget)) hide(button);
    }));
    window.addEventListener('scroll', () => current && hide(current), { passive: true, capture: true });
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
    fitVisibleRows();
}

// Pending and Catered: as tall as their first rows (data-visible-rows), the rest by scrolling inside the list.
function fitVisibleRows() {
    document.querySelectorAll('[data-visible-rows]').forEach((box) => {
        const visible = Number(box.dataset.visibleRows);
        const rows = box.querySelectorAll('tbody > tr');
        if (rows.length <= visible) {
            box.style.maxHeight = '';
            return;
        }
        // Down to the last visible row's bottom, plus the horizontal scrollbar's height.
        const bottom = rows[visible - 1].getBoundingClientRect().bottom - box.getBoundingClientRect().top + box.scrollTop;
        box.style.maxHeight = `${Math.ceil(bottom + box.offsetHeight - box.clientHeight)}px`;
    });
}

// Copy text to the clipboard; falls back to execCommand where the Clipboard API is unavailable (plain http).
async function copyText(text) {
    try {
        await navigator.clipboard.writeText(text);
    } catch {
        const area = Object.assign(document.createElement('textarea'), { value: text });
        area.style.cssText = 'position:fixed;opacity:0';
        document.body.append(area);
        area.select();
        document.execCommand('copy');
        area.remove();
    }
}

// The copy icon by a list's Contact # header: every contact number in that list, space-separated ("9938742536 9507297299").
function initCopyPhones() {
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-copy-phones]');
        if (!button) return;

        const phones = [...button.closest('table').querySelectorAll('tbody td[data-col="contact_number"] a[href^="tel:"]')]
            .map((link) => link.textContent.trim())
            .filter(Boolean);
        if (!phones.length) return;

        await copyText(phones.join(' '));
        button.title = `Copied ${phones.length} number${phones.length === 1 ? '' : 's'}`;
        button.querySelector('[data-copy-icon]').classList.add('hidden');
        button.querySelector('[data-copied-icon]').classList.remove('hidden');
        setTimeout(() => {
            button.title = 'Copy all contact numbers';
            button.querySelector('[data-copy-icon]').classList.remove('hidden');
            button.querySelector('[data-copied-icon]').classList.add('hidden');
        }, 1500);
    });
}

// Right-click a lead row: Copy name / Copy mobile number, Mark as catered (Pending) or Unmark catered (Catered), and on a
// Pending row "Transfer to <CRA>" when another CRA handled the customer's latest order (looked up as the menu opens).
function initRowMenu() {
    const menu = document.createElement('div');
    menu.setAttribute('role', 'menu');
    menu.hidden = true;
    menu.className = 'fixed z-50 min-w-48 overflow-hidden rounded-lg border border-line bg-white py-1 text-sm shadow-lg';
    const itemClass = 'flex w-full items-center gap-2 px-3 py-2 text-left font-medium hover:bg-brand-50 focus-visible:bg-brand-50 focus-visible:outline-none';
    const menuItem = (label) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.setAttribute('role', 'menuitem');
        button.className = itemClass;
        if (label) button.textContent = label;
        return button;
    };
    const copyName = menuItem('Copy name');
    const copyPhone = menuItem('Copy mobile number');
    const divider = document.createElement('div');
    divider.className = 'my-1 border-t border-line';
    const item = menuItem();
    const handover = menuItem();
    let handoverTo = null;
    menu.append(copyName, copyPhone, divider, item, handover);
    document.body.append(menu);

    let row = null;
    const close = () => {
        menu.hidden = true;
        row = null;
    };

    // The CRA of the customer's latest order, if it isn't this row's CRA; shown while the menu is still open on that row.
    const loadHandover = async (target) => {
        handoverTo = null;
        handover.hidden = !target.dataset.handoverUrl;
        if (handover.hidden) return;
        handover.disabled = true;
        handover.textContent = 'Checking last order…';
        try {
            const response = await fetch(target.dataset.handoverUrl, { headers: { Accept: 'application/json' } });
            const { cra } = await response.json();
            if (row !== target) return;
            if (!cra) {
                handover.hidden = true;
                return;
            }
            handoverTo = cra;
            handover.disabled = false;
            handover.innerHTML = '<svg class="size-4 text-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-5-5 5 5-5 5"/></svg>';
            handover.append(`Transfer to ${cra.name}`);
            handover.title = `${cra.name} handled their last order (${cra.ordered_on}).`;
        } catch {
            if (row === target) handover.hidden = true;
        }
    };

    document.addEventListener('contextmenu', (event) => {
        const target = event.target.closest('tr[data-lead-row]');
        // Rows the user can't edit (no data-lead-row) keep the browser's own menu.
        if (!target) return close();
        // Highlighted text or a field: keep the browser's menu so Copy / Paste work as usual.
        const selection = window.getSelection();
        if ((selection && !selection.isCollapsed && target.contains(selection.anchorNode))
            || event.target.closest('input, textarea, select')) return close();
        event.preventDefault();
        row = target;
        copyPhone.hidden = !row.dataset.phone;
        const toProcessed = row.dataset.mark === 'processed';
        item.innerHTML = `<span aria-hidden="true" class="size-2.5 rounded-full ${toProcessed ? 'bg-teal' : 'bg-coral'}"></span>`;
        item.append(toProcessed ? 'Mark as catered' : 'Unmark catered');
        loadHandover(row);
        menu.hidden = false;
        // At the pointer; from the keyboard (menu key), under the row's first shown cell (columns can be hidden).
        const at = event.clientX || event.clientY ? { x: event.clientX, y: event.clientY } : (() => {
            const rect = ([...row.cells].find((cell) => cell.getClientRects().length) ?? row).getBoundingClientRect();
            return { x: rect.left + 16, y: rect.bottom };
        })();
        menu.style.left = `${Math.min(at.x, window.innerWidth - menu.offsetWidth - 8)}px`;
        menu.style.top = `${Math.min(at.y, window.innerHeight - menu.offsetHeight - 8)}px`;
        copyName.focus();
    });

    copyName.addEventListener('click', () => {
        const text = row?.dataset.customer;
        close();
        if (text) copyText(text);
    });
    copyPhone.addEventListener('click', () => {
        const text = row?.dataset.phone;
        close();
        if (text) copyText(text);
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

    handover.addEventListener('click', async () => {
        const target = row;
        const cra = handoverTo;
        if (!target || !cra) return;
        close();
        if (!confirm(`Transfer ${target.dataset.customer} to ${cra.name}? ${cra.name} handled their last order (${cra.ordered_on}).`)) return;

        const data = new FormData();
        data.append('_token', document.querySelector('meta[name=csrf-token]')?.content || '');

        target.style.transition = 'opacity 200ms';
        target.style.opacity = '0.35';
        try {
            await send(target.dataset.handoverUrl, data);
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

// Full view: a horizontal scrollbar pinned to the bottom mirrors the wide table's (for mice without sideways scrolling).
// The list is swapped in place after Mark as catered, so the table is looked up each time.
function initScrollSlider() {
    const bar = document.querySelector('[data-x-scrollbar]');
    if (!bar) return;
    const width = bar.querySelector('[data-x-scrollbar-width]');
    const table = () => document.querySelector('[data-lead-section] .overflow-x-auto');
    // The position each element was last moved to by the other, so the scroll event that move fires is ignored.
    // A time-based guard let that echo through late and pulled the scrollbar back mid-drag.
    const moved = new Map();

    const copy = (from, to) => {
        const left = from.scrollLeft;
        if (Math.abs(to.scrollLeft - left) < 1) return;
        moved.set(to, left);
        to.scrollLeft = left;
    };
    const follow = (el, other) => {
        const echo = moved.get(el);
        moved.delete(el);
        if (echo !== undefined && Math.abs(el.scrollLeft - echo) < 1) return;
        copy(el, other);
    };

    const sync = () => {
        const wrap = table();
        bar.hidden = !wrap || wrap.scrollWidth <= wrap.clientWidth;
        const padding = bar.hidden ? '' : `${bar.offsetHeight}px`;
        if (document.body.style.paddingBottom !== padding) document.body.style.paddingBottom = padding;
        if (bar.hidden) return;
        // Same left edge and width as the table, so both scroll the same distance.
        bar.style.left = `${wrap.getBoundingClientRect().left}px`;
        bar.style.width = `${wrap.clientWidth}px`;
        width.style.width = `${wrap.scrollWidth}px`;
        copy(wrap, bar);
    };
    let queued = false;
    const queueSync = () => {
        if (queued) return;
        queued = true;
        requestAnimationFrame(() => { queued = false; sync(); });
    };

    bar.addEventListener('scroll', () => {
        const wrap = table();
        if (wrap) follow(bar, wrap);
    }, { passive: true });
    document.addEventListener('scroll', (event) => {
        if (event.target === table()) follow(event.target, bar);
    }, { capture: true, passive: true });

    // Hidden columns, a swapped list or a resized pop-up change how far the table can move; measured once per frame.
    new MutationObserver(queueSync).observe(document.body, { childList: true, subtree: true });
    new ResizeObserver(queueSync).observe(document.body);
    sync();
}

// A CRA's hello pop-up, opened on their first visit of the day.
function initGreeting() {
    const dialog = document.getElementById('greeting-dialog');
    if (!dialog) return;
    dialog.querySelector('[data-greeting-close]').addEventListener('click', () => dialog.close());
    dialog.showModal();
}

// Product Performance → Segmentation Summary: NO VERBAL CONV's slice or legend row flips the Customer's Feedback card to its reasons; "Back to chart" flips it back.
function initFlip() {
    const inner = document.querySelector('[data-flip-inner]');
    if (!inner?.querySelector('[data-flip-face="back"]')) return;
    const faces = { front: inner.querySelector('[data-flip-face="front"]'), back: inner.querySelector('[data-flip-face="back"]') };

    const flip = (toBack) => {
        inner.toggleAttribute('data-flipped', toBack);
        // Only the side facing the user can be tabbed into or read out.
        faces.front.inert = toBack;
        faces.back.inert = !toBack;
        (toBack ? faces.back.querySelector('[data-flip-close]') : faces.front.querySelector('button[data-flip-open]'))?.focus({ preventScroll: true });
    };

    inner.addEventListener('click', (event) => {
        if (event.target.closest('[data-flip-open]')) flip(true);
        else if (event.target.closest('[data-flip-close]')) flip(false);
    });
}

// Call buttons: the server sends the number to the CRA's phone, which dials it; the label says how it went.
function initCallButtons() {
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-call-url]');
        if (!button || button.disabled) return;

        const label = button.querySelector('[data-call-label]');
        const data = new FormData();
        data.append('_token', document.querySelector('meta[name=csrf-token]')?.content || '');
        button.disabled = true;
        label.textContent = 'Calling…';

        try {
            const body = await send(button.dataset.callUrl, data);
            label.textContent = 'Phone dialling';
            button.title = body.message || '';
            flash(button, true, body.message);
        } catch (error) {
            label.textContent = 'Call';
            button.disabled = false;
            flash(button, false, error.message);
            alert(error.message);
        } finally {
            // Re-enabled after a pause so a double click doesn't dial twice.
            setTimeout(() => {
                button.disabled = false;
                label.textContent = 'Call';
            }, 8000);
        }
    });
}

// Call Recording column: a call's button opens the player dialog and starts it; closing stops it.
function initRecordingPlayer() {
    const dialog = document.getElementById('recording-dialog');
    if (!dialog) return;

    const audio = dialog.querySelector('[data-recording-audio]');
    const error = dialog.querySelector('[data-recording-error]');

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-recording-url]');
        if (!button) return;

        dialog.querySelector('[data-recording-title]').textContent = button.dataset.recordingTitle || 'Call recording';
        dialog.querySelector('[data-recording-meta]').textContent = button.dataset.recordingMeta || '';
        error.hidden = true;
        audio.src = button.dataset.recordingUrl;
        dialog.showModal();
        audio.play().catch(() => {});
    });

    audio.addEventListener('error', () => {
        if (audio.getAttribute('src')) error.hidden = false;
    });
    dialog.querySelector('[data-recording-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });
    dialog.addEventListener('close', () => {
        audio.pause();
        audio.removeAttribute('src');
        audio.load();
    });
}

// Cold leads' Attempt button: logs today's attempt, or takes it back when already logged today.
function initAttempts() {
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-attempt]');
        if (!button || button.disabled) return;

        const undo = button.getAttribute('aria-pressed') === 'true';
        const data = new FormData();
        data.append('_token', document.querySelector('meta[name=csrf-token]')?.content || '');
        if (undo) data.append('_method', 'DELETE');
        button.disabled = true;

        try {
            renderAttempt(button, await send(undo ? button.dataset.undoUrl : button.dataset.storeUrl, data));
            flash(button, true, button.title);
        } catch (error) {
            button.disabled = false;
            flash(button, false, error.message);
            alert(error.message);
        }
    });

    // Tagging a lead cold (or not) shows or hides its button straight away.
    document.addEventListener('change', (event) => {
        if (event.target.name !== 'customer_tag') return;
        const button = event.target.closest('tr')?.querySelector('[data-attempt]');
        if (button) button.hidden = !JSON.parse(button.dataset.coldTags).includes(event.target.value);
    });
}

// Reserved - Pancake's add form: Find shows what the contact number fills in (their last order) and the name.
function initReservationForm() {
    const form = document.querySelector('[data-reservation-form]');
    const phone = form.querySelector('[data-reservation-phone]');
    const name = form.querySelector('[data-reservation-name]');
    const found = form.querySelector('[data-reservation-found]');
    const button = form.querySelector('[data-reservation-find]');

    button.addEventListener('click', async () => {
        if (!phone.value.trim()) return phone.focus();
        button.disabled = true;
        try {
            const url = `${form.dataset.lookupUrl}?${new URLSearchParams({ phone: phone.value })}`;
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            const body = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(body.message || `Couldn't look the number up (HTTP ${response.status}).`);
            if (body.customer?.customer_name && !name.value.trim()) name.value = body.customer.customer_name;
            found.textContent = body.summary;
        } catch (error) {
            found.textContent = error.message;
        } finally {
            button.disabled = false;
        }
    });
}

function renderAttempt(button, { left, today, history }) {
    button.dataset.left = left;
    button.setAttribute('aria-pressed', today ? 'true' : 'false');
    button.disabled = !today && left === 0;
    button.title = (history ? `Attempts: ${history}` : 'No attempts yet') + (today ? " · click to take back today's" : '');
    button.querySelector('[data-attempt-label]').textContent = today ? '✓ Tried today' : left ? 'Log attempt' : 'No attempts left';
    button.querySelector('[data-attempt-left]').textContent = left ? `· ${left} left` : '';
}

export function initSegmentation() {
    initExpand();
    initFlip();
    initGreeting();
    initScrollSlider();
    if (document.querySelector('[data-visible-rows]')) {
        fitVisibleRows();
        // Hidden columns, fonts loading or a narrower window change the rows' heights.
        new ResizeObserver(() => fitVisibleRows()).observe(document.body);
    }
    if (document.querySelector('[data-lead-section]')) initRowMenu();
    if (document.querySelector('[data-lead-section]')) initCopyPhones();
    if (document.querySelector('[data-lead-section]')) initOrderHistory();
    if (document.querySelector('[data-call-url]')) initCallButtons();
    initRecordingPlayer();
    if (document.querySelector('[data-attempt]')) initAttempts();
    if (document.querySelector('[data-reservation-form]')) initReservationForm();
    if (!document.querySelector('[data-column-picker], form[data-autosave], [data-live-summary], #transfer-dialog')) return;
    initColumns();
    initLiveSummary();
    initPerCra();
    initTransfer();
    initAutosave();
    initNotes();
    if (document.querySelector('[data-feedback-menu]')) initFeedbackMenus();
}

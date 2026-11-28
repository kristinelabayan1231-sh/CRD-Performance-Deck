// The tracker's texts: text a STOP customer again, the Pending list's text chips, and the customer panel's tabs
// (Conversation, Order, Spiels; the panel itself is opened by spiels.js).

const escapeHtml = (text) => String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

export function initSmsPanel() {
    // A customer who replied STOP: ticking them, once confirmed, lets them be texted again.
    document.addEventListener('change', (event) => {
        const box = event.target instanceof Element ? event.target.closest('[data-sms-allow]') : null;
        if (!box) return;
        if (confirm(`${box.dataset.smsAllow || 'This customer'} asked not to be texted. Text them again?`)) {
            box.form.submit();
        } else {
            box.checked = false;
        }
    });

    // Pending's chips: only the page's rows with that text (All shows them all again).
    document.addEventListener('click', (event) => {
        const chip = event.target instanceof Element ? event.target.closest('[data-sms-filter]') : null;
        if (!chip) return;
        const section = chip.closest('[data-lead-section]') || document;
        const state = chip.dataset.smsFilter;
        section.querySelectorAll('[data-sms-filter]').forEach((other) => other.setAttribute('aria-pressed', String(other === chip)));
        section.querySelectorAll('tr[data-sms-state]').forEach((row) => {
            row.hidden = state !== '' && row.dataset.smsState !== state;
        });
    });

    const panel = document.querySelector('[data-spiels-panel]');
    if (!panel) return;
    const frame = panel.querySelector('[data-panel-pane="conversation"] iframe');
    const conversationTab = panel.querySelector('[data-panel-tab-button="conversation"]');
    let loads = 0;
    let ordersFor = null;

    // Something sent or marked in a conversation: the rows' text state is stale, so the page reloads when it closes.
    frame.addEventListener('load', () => {
        if (frame.src !== 'about:blank') loads += 1;
    });
    new MutationObserver(() => {
        if (!panel.hidden) return;
        if (loads > 1) return window.location.reload();
        frame.src = 'about:blank';
        delete frame.dataset.for;
        loads = 0;
    }).observe(panel, { attributes: true, attributeFilter: ['hidden'] });

    async function showOrders() {
        const url = panel.dataset.ordersUrl;
        const list = panel.querySelector('[data-panel-orders]');
        const empty = panel.querySelector('[data-panel-orders-empty]');
        if (!url || ordersFor === url) return;
        ordersFor = url;
        list.innerHTML = '<li class="flex items-center gap-2 text-sm text-muted"><span class="spinner" aria-hidden="true"></span>Loading…</li>';
        empty.hidden = true;
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            const { orders = [] } = response.ok ? await response.json() : {};
            if (ordersFor !== url) return;
            list.innerHTML = orders.map((order) => `
                <li class="flex items-baseline justify-between gap-3 rounded-xl border border-line px-3 py-2.5 text-sm">
                    <span class="font-semibold">${escapeHtml(order.product)}</span>
                    <span class="shrink-0 text-xs text-muted tabular-nums">${escapeHtml(order.date)}</span>
                </li>`).join('');
            empty.hidden = orders.length > 0;
        } catch {
            list.innerHTML = '';
            empty.hidden = false;
            ordersFor = null;
        }
    }

    function show(tab) {
        if (tab === 'conversation' && !panel.dataset.smsChat) tab = 'spiels';
        panel.querySelectorAll('[data-panel-tab-button]').forEach((button) => button.setAttribute('aria-selected', String(button.dataset.panelTabButton === tab)));
        panel.querySelectorAll('[data-panel-pane]').forEach((pane) => {
            pane.hidden = pane.dataset.panelPane !== tab;
        });
        if (tab === 'conversation' && frame.dataset.for !== panel.dataset.smsChat) {
            // The next customer's first load isn't a change; changes made before it still count.
            loads = loads > 1 ? loads : 0;
            frame.dataset.for = panel.dataset.smsChat;
            frame.src = panel.dataset.smsChat;
        }
        if (tab === 'order') showOrders();
    }

    panel.addEventListener('customer-panel:open', (event) => {
        conversationTab.hidden = !panel.dataset.smsChat;
        ordersFor = ordersFor === panel.dataset.ordersUrl ? ordersFor : null;
        show(event.detail.tab);
    });
    panel.querySelectorAll('[data-panel-tab-button]').forEach((button) => button.addEventListener('click', () => show(button.dataset.panelTabButton)));
}

// Spiels: clicking a Segmentation Tracker row opens the customer's Pancake chat in its own window on the left of the
// screen and the customer panel on the right (Conversation / Order / Spiels tabs, sms-panel.js shows them), with the user's spiels (rich text) filled in for the customer to copy into
// the chat. Copy puts both the formatted text and a plain one (• bullets, line breaks) on the clipboard, so a box that
// takes only plain text still gets it tidy.
// Settings → My Spiels: the rich-text editor, Insert {name}/{product} at the cursor, and drag spiels into order.

const PANEL_WIDTH = 440;

// Clicks on these inside a row keep doing their own thing.
const INTERACTIVE = 'a, button, input, select, textarea, label, summary, audio, video, [contenteditable], [data-note-popover]';

const BLOCKS = ['P', 'DIV', 'UL', 'OL'];

// The clicked row, every cell included (the sticky name cell has its own white background).

const initials = (name = '') => name.trim().split(/\s+/).filter(Boolean).slice(0, 2).map((word) => word[0].toUpperCase()).join('') || '?';

const escapeHtml = (text) => text.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

// "ANALIZA M GARCIA" or "analiza m garcia" → "Analiza".
function firstName(customer) {
    const first = (customer || '').trim().split(/\s+/)[0] || '';
    return first.charAt(0).toUpperCase() + first.slice(1).toLowerCase();
}

// The spiel's HTML (cleaned by the server) with {name} and {product} filled in.
function fill(html, lead) {
    return html.replaceAll('{name}', escapeHtml(firstName(lead.customer))).replaceAll('{product}', escapeHtml(lead.product || ''));
}

// Rich text as plain text: line breaks kept, list items as "• " or "1. ".
function plainText(html) {
    // A list item or block starts on its own line and ends one.
    const walk = (node) => {
        let out = '';
        const newLine = () => {
            if (out && !out.endsWith('\n')) out += '\n';
        };

        for (const child of node.childNodes) {
            if (child.nodeType === Node.TEXT_NODE) {
                out += child.textContent;
            } else if (child.nodeName === 'BR') {
                out += '\n';
            } else if (child.nodeName === 'LI') {
                const list = child.parentNode;
                newLine();
                out += `${list.nodeName === 'OL' ? `${[...list.children].indexOf(child) + 1}. ` : '• '}${walk(child).trim()}\n`;
            } else if (BLOCKS.includes(child.nodeName)) {
                newLine();
                out += walk(child).replace(/\n?$/, '\n');
            } else {
                out += walk(child);
            }
        }

        return out;
    };

    const root = document.createElement('div');
    root.innerHTML = html;
    return walk(root).replace(/\n{3,}/g, '\n\n').trim();
}

async function copyRich(html) {
    const plain = plainText(html);
    try {
        await navigator.clipboard.write([new ClipboardItem({
            'text/html': new Blob([html], { type: 'text/html' }),
            'text/plain': new Blob([plain], { type: 'text/plain' }),
        })]);
    } catch {
        await navigator.clipboard?.writeText(plain);
    }
}

// The chat window: the screen's left side, leaving room for the panel. A named window is reused for the next row.
function openChatWindow(url = '') {
    const width = Math.max(600, (window.screen.availWidth || 1440) - PANEL_WIDTH);
    const height = window.screen.availHeight || 900;

    return window.open(url, 'pancake-chat', `popup=yes,left=0,top=0,width=${width},height=${height}`);
}

function initPanel() {
    const panel = document.querySelector('[data-spiels-panel]');
    if (!panel) return;

    const spiels = JSON.parse(panel.dataset.spiels || '[]').map((spiel) => ({ ...spiel, text: plainText(spiel.body).toLowerCase() }));
    const list = panel.querySelector('[data-spiels-list]');
    const search = panel.querySelector('[data-spiels-search]');
    const part = (name) => panel.querySelector(`[data-spiels-${name}]`);
    let lead = null;
    let row = null;
    let chatUrl = null;
    let copiedId = null;
    let request = 0;

    function renderSpiels() {
        const query = search.value.trim().toLowerCase();
        const shown = spiels.filter((spiel) => !query || `${spiel.title.toLowerCase()} ${spiel.text}`.includes(query));

        part('empty').hidden = spiels.length > 0;
        list.innerHTML = shown.map((spiel) => `
            <li class="rounded-xl border border-line bg-white p-3">
                <div class="flex items-center gap-2">
                    <p class="min-w-0 flex-1 text-sm font-semibold">${escapeHtml(spiel.title)}</p>
                    ${spiel.id === copiedId
                        ? `<button type="button" data-spiel-copy="${spiel.id}" class="flex h-8 items-center gap-1 rounded-full bg-teal/15 px-3 text-xs font-semibold text-teal-700"><svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 5 5L19 7"/></svg>Copied</button>`
                        : `<button type="button" data-spiel-copy="${spiel.id}" class="flex h-8 items-center gap-1 rounded-full bg-brand-600 px-3 text-xs font-semibold text-white hover:bg-brand-700"><svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/></svg>Copy</button>`}
                </div>
                <div class="spiel-rich mt-1.5 text-sm text-muted">${fill(spiel.body, lead)}</div>
            </li>`).join('');
    }

    function close() {
        panel.hidden = true;
        row?.removeAttribute('data-picked');
        row = null;
        showPeek();
    }

    // The page's customers with a reply to answer (Wants to order, Needs reply), in list order.
    const toAnswer = () => [...document.querySelectorAll('tr[data-spiel-row]')].filter((tr) => !tr.hidden && ['yes', 'replied'].includes(tr.dataset.smsState));

    // Header: what the customer needs, and (for one with a reply) their place among the page's replies.
    function showPager() {
        const state = row.dataset.smsState;
        panel.querySelector('[data-panel-kick]').textContent = { yes: 'Wants to order', replied: 'Needs reply' }[state] || 'Customer';
        const rows = toAnswer();
        const at = rows.indexOf(row);
        panel.querySelector('[data-panel-pager]').hidden = at < 0 || rows.length < 2;
        panel.querySelector('[data-panel-pos]').textContent = `${at + 1}/${rows.length}`;
        showPeek();
    }

    function step(by) {
        const rows = toAnswer();
        const at = rows.indexOf(row);
        if (at < 0 || !rows.length) return;
        open(rows[(at + by + rows.length) % rows.length], { withChat: false });
    }

    // The floating messages button: the replies to answer on this page, while the panel is closed.
    const peek = document.querySelector('[data-panel-peek]');
    function showPeek() {
        if (!peek) return;
        const count = toAnswer().length;
        peek.hidden = !panel.hidden || count === 0;
        peek.querySelector('[data-panel-peek-count]').textContent = count;
    }

    // tab: the tab to show (sms-panel.js); withChat: open the Pancake chat window too (a row click, not a text button).
    async function open(clicked, { tab = null, withChat = true } = {}) {
        const id = ++request;
        row?.removeAttribute('data-picked');
        row = clicked;
        row.dataset.picked = '';
        lead = {
            customer: row.dataset.spielCustomer,
            product: row.dataset.spielProduct,
            phone: row.dataset.spielPhone,
        };
        chatUrl = null;
        copiedId = null;

        part('customer').textContent = lead.customer;
        part('lead').textContent = [lead.phone, [row.dataset.spielQty, lead.product].filter(Boolean).join(' × ')].filter(Boolean).join(' · ');
        panel.querySelector('[data-panel-cra]').textContent = row.dataset.spielCra ? `CRA ${row.dataset.spielCra}` : '';
        panel.querySelector('[data-panel-initials]').textContent = initials(lead.customer);
        part('finding').hidden = false;
        part('chat').hidden = true;
        part('no-chat').hidden = true;
        part('copy-phone').textContent = 'Copy number';
        // The row's conversation, order history and call button, for the panel's tabs and its Call.
        panel.dataset.smsChat = row.dataset.smsChat || '';
        panel.dataset.ordersUrl = row.dataset.ordersUrl || '';
        const call = panel.querySelector('[data-panel-call]');
        const rowCall = row.querySelector('[data-call-url]');
        call.hidden = !rowCall;
        if (rowCall) call.dataset.callUrl = rowCall.dataset.callUrl;
        else delete call.dataset.callUrl;
        panel.hidden = false;
        renderSpiels();
        const state = row.dataset.smsState;
        showPager();
        panel.dispatchEvent(new CustomEvent('customer-panel:open', {
            detail: { tab: tab || (row.dataset.smsChat && (state === 'yes' || state === 'replied') ? 'conversation' : 'spiels') },
        }));

        // Opened now, while the click still counts as the user's, so the browser doesn't block it; the chat loads into it.
        const chatWindow = withChat ? openChatWindow() : null;

        try {
            const response = await fetch(row.dataset.chatUrl, { headers: { Accept: 'application/json' } });
            const data = response.ok ? await response.json() : { url: null };
            if (id !== request) return;

            chatUrl = data.url;
            if (chatUrl && chatWindow) {
                chatWindow.location.href = chatUrl;
            } else {
                chatWindow?.close();
            }
        } catch {
            if (id !== request) return;
            chatWindow?.close();
        }

        part('finding').hidden = true;
        part('chat').hidden = !chatUrl;
        part('no-chat').hidden = !!chatUrl;
    }

    // Capture phase: a click that only closes the row's right-click menu (still open here) doesn't open a chat.
    document.addEventListener('click', (event) => {
        const clicked = event.target.closest('tr[data-spiel-row]');
        if (!clicked || panel.dataset.rowOpen === 'off' || event.target.closest(INTERACTIVE) || window.getSelection()?.toString()
            || document.querySelector('[role="menu"]:not([hidden])')) return;
        open(clicked);
    }, true);

    // A row's text button: the panel on its Conversation or Order, without the Pancake window.
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-conversation-open]');
        const clicked = button?.closest('tr[data-spiel-row]');
        if (!clicked) return;
        open(clicked, { tab: button.dataset.panelTab || 'conversation', withChat: false });
    });

    part('close').addEventListener('click', close);
    panel.querySelector('[data-panel-prev]').addEventListener('click', () => step(-1));
    panel.querySelector('[data-panel-next]').addEventListener('click', () => step(1));
    panel.querySelector('[data-panel-order]').addEventListener('click', () => panel.querySelector('[data-panel-tab-button="order"]').click());
    peek?.addEventListener('click', () => toAnswer()[0] && open(toAnswer()[0], { withChat: false }));
    showPeek();
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !panel.hidden && !document.querySelector('dialog[open]')) close();
    });
    part('open-chat').addEventListener('click', () => chatUrl && openChatWindow(chatUrl)?.focus());
    part('copy-phone').addEventListener('click', (event) => {
        navigator.clipboard?.writeText(lead?.phone || '');
        event.currentTarget.textContent = 'Copied';
    });
    search.addEventListener('input', renderSpiels);

    list.addEventListener('click', (event) => {
        const button = event.target.closest('[data-spiel-copy]');
        if (!button) return;
        const spiel = spiels.find((s) => String(s.id) === button.dataset.spielCopy);
        copyRich(fill(spiel.body, lead));
        copiedId = spiel.id;
        renderSpiels();
    });
}

// The rich-text editor: toolbar commands on the selection, and the hidden "body" input kept in step.
function initEditors() {
    document.querySelectorAll('[data-spiel-editor]').forEach((editor) => {
        const box = editor.querySelector('[data-rte-box]');
        const input = editor.querySelector('[data-rte-input]');
        const sync = () => {
            input.value = box.innerHTML;
        };

        editor.querySelectorAll('[data-rte]').forEach((button) => {
            // mousedown keeps the selection in the box.
            button.addEventListener('mousedown', (event) => event.preventDefault());
            button.addEventListener('click', () => {
                box.focus();
                document.execCommand(button.dataset.rte);
                sync();
            });
        });
        box.addEventListener('input', sync);
        editor.closest('form')?.addEventListener('submit', sync);
    });

    // Insert a placeholder at the cursor in the same form's editor.
    document.querySelectorAll('[data-spiel-insert]').forEach((button) => {
        button.addEventListener('mousedown', (event) => event.preventDefault());
        button.addEventListener('click', () => {
            const box = button.closest('[data-spiel-form]')?.querySelector('[data-rte-box]');
            if (!box) return;
            if (!box.contains(document.getSelection()?.anchorNode)) {
                box.focus();
                document.getSelection()?.selectAllChildren(box);
                document.getSelection()?.collapseToEnd();
            }
            document.execCommand('insertText', false, button.dataset.spielInsert);
            box.dispatchEvent(new Event('input'));
        });
    });
}

// Drag to reorder; the new order is saved at once.
function initReorder() {
    const list = document.querySelector('[data-spiel-list]');
    if (!list) return;
    let dragged = null;

    // Only the grip starts a drag, so text in an open editor can still be selected.
    list.addEventListener('mousedown', (event) => {
        const item = event.target.closest('[data-spiel-id]');
        if (item) item.draggable = !!event.target.closest('[data-spiel-handle]');
    });
    list.addEventListener('dragstart', (event) => {
        dragged = event.target.closest('[data-spiel-id]');
        dragged?.classList.add('opacity-50');
    });
    list.addEventListener('dragover', (event) => {
        const over = event.target.closest('[data-spiel-id]');
        if (!dragged || !over || over === dragged) return;
        event.preventDefault();
        const after = event.clientY > over.getBoundingClientRect().top + over.offsetHeight / 2;
        over[after ? 'after' : 'before'](dragged);
    });
    list.addEventListener('dragend', () => {
        if (!dragged) return;
        dragged.classList.remove('opacity-50');
        dragged.draggable = false;
        dragged = null;
        fetch(list.dataset.reorderUrl, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ ids: [...list.querySelectorAll('[data-spiel-id]')].map((item) => Number(item.dataset.spielId)) }),
        });
    });
}

export function initSpiels() {
    initPanel();
    initEditors();
    initReorder();
}

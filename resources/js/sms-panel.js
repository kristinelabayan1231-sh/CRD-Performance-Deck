// The tracker's Text column: a customer's conversation in a panel on the right. Text them again after a STOP.

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

    const panel = document.querySelector('[data-conversation-panel]');
    if (!panel) return;
    const frame = panel.querySelector('iframe');
    let loads = 0;
    let opener = null;

    frame.addEventListener('load', () => {
        if (!panel.hidden) loads += 1;
    });

    const close = () => {
        if (panel.hidden) return;
        panel.hidden = true;
        // Something was sent or marked in the conversation: refresh the rows' SMS state.
        if (loads > 1) return window.location.reload();
        frame.src = 'about:blank';
        opener?.focus();
    };

    // Delegated: the lists are swapped in place after Mark as catered.
    document.addEventListener('click', (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-conversation-open]') : null;
        if (!button) return;
        opener = button;
        panel.querySelector('[data-conversation-title]').textContent = button.dataset.conversationName || 'Customer';
        // The next customer's first load isn't a change; changes made before it still count.
        loads = loads > 1 ? loads : 0;
        panel.hidden = false;
        frame.src = button.dataset.conversationOpen;
        panel.querySelector('[data-conversation-close]').focus();
    });
    panel.querySelector('[data-conversation-close]').addEventListener('click', close);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') close();
    });
}

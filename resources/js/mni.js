// Monthly MNI: each yellow box saves when it changes (empty clears it), then the statement below is redrawn from the server.
async function send(url, body) {
    const response = await fetch(url, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
        },
        body: JSON.stringify(body),
    });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
}

export function initMni() {
    const root = document.querySelector('[data-mni]');
    if (!root) return;
    const host = root.querySelector('[data-mni-ledger-host]');

    const redraw = async () => {
        const response = await fetch(root.dataset.ledgerUrl, { headers: { 'X-Mni-Ledger': '1', Accept: 'text/html' } });
        if (response.ok) host.innerHTML = await response.text();
    };

    root.addEventListener('change', async (event) => {
        const input = event.target.closest('[data-mni-input]');
        if (!input) return;
        const raw = input.value.replace(/[,%₱\s]/g, '');
        const value = raw === '' ? null : Number(raw);
        if (value !== null && (Number.isNaN(value) || value < 0)) {
            input.classList.add('border-coral');
            input.title = 'Type a number of 0 or more.';
            return;
        }
        try {
            await send(root.dataset.url, { month: root.dataset.month, plan: root.dataset.plan, cra: input.dataset.cra || null, field: input.dataset.field, value });
            input.classList.remove('border-coral');
            input.title = '';
            await redraw();
        } catch {
            input.classList.add('border-coral');
            input.title = "Couldn't save; try again.";
        }
    });
}

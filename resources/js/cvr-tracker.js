// CVR Tracker: each order's Upsell, Type of Order and Point of Contact save as they're picked, and the day's
// Unconfirmed when its box changes; the sales by type, Assigned / Pancake (a Segmentation order is always Assigned),
// the lead counts, Contacted CX and status Total follow on screen.
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

const peso = (n) => `₱${Math.round(n).toLocaleString('en-US')}`;

function recount(root) {
    const sums = { bc_orders: 0, bc_amount: 0, sc_orders: 0, sc_amount: 0, contacted: 0, assigned_leads: 0, pancake_leads: 0 };
    root.querySelectorAll('[data-cvr-order]').forEach((row) => {
        const amount = Number(row.dataset.amount);
        const type = row.querySelector('[data-cvr-field="order_type"]').value;
        const prefix = type === 'broadcast' ? 'bc' : 'sc';
        sums[`${prefix}_orders`] += 1;
        sums[`${prefix}_amount`] += amount;
        if (row.querySelector('[data-cvr-field="point_of_contact"]').value) sums.contacted += 1;
        const assigned = type === 'segmentation' || row.dataset.matched === '1';
        sums[assigned ? 'assigned_leads' : 'pancake_leads'] += 1;
        row.querySelector('[data-cvr-source="assigned"]')?.classList.toggle('hidden', !assigned);
        row.querySelector('[data-cvr-source="pancake"]')?.classList.toggle('hidden', assigned);
    });
    root.querySelectorAll('[data-cvr-sum]').forEach((el) => {
        const key = el.dataset.cvrSum;
        el.textContent = key.endsWith('_amount') ? peso(sums[key]) : sums[key].toLocaleString('en-US');
    });
}

function restatusTotal(root) {
    const unconfirmed = Number(root.querySelector('[data-cvr-unconfirmed]')?.value || 0);
    const rest = [...root.querySelectorAll('[data-cvr-status]')].reduce((sum, el) => sum + Number(el.dataset.cvrStatus), 0);
    const total = root.querySelector('[data-cvr-status-total]');
    if (total) total.textContent = (unconfirmed + rest).toLocaleString('en-US');
}

const flag = (el, failed) => {
    el.classList.toggle('ring-2', failed);
    el.classList.toggle('ring-coral', failed);
    el.title = failed ? "Couldn't save; try again." : '';
};

export function initCvrTracker() {
    const root = document.querySelector('[data-cvr-tracker]');
    if (!root) return;

    root.addEventListener('change', async (event) => {
        const select = event.target.closest('[data-cvr-field]');
        if (select) {
            // Recolour the pill like the Segmentation Tracker's dropdowns.
            const colors = JSON.parse(select.dataset.colors);
            Object.values(colors).concat(root.dataset.emptyClasses).forEach((c) => select.classList.remove(...c.split(' ')));
            select.classList.add(...(colors[select.value] || root.dataset.emptyClasses).split(' '));

            if (select.dataset.tag !== undefined) {
                select.parentElement.querySelector('[data-cvr-edited]')?.classList.toggle('hidden', select.value === select.dataset.tag);
            }
            recount(root);

            try {
                await send(select.closest('[data-cvr-order]').dataset.url, { [select.dataset.cvrField]: select.value || null });
                flag(select, false);
            } catch {
                flag(select, true);
            }
            return;
        }

        const input = event.target.closest('[data-cvr-unconfirmed]');
        if (input) {
            const value = input.value === '' ? null : Math.max(0, Math.floor(Number(input.value)));
            if (value !== null) input.value = value;
            restatusTotal(root);
            try {
                await send(root.dataset.dayUrl, { cra: root.dataset.cra, date: root.dataset.date, unconfirmed: value });
                flag(input, false);
            } catch {
                flag(input, true);
            }
        }
    });
}

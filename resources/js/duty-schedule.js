// On-duty pop-up (Segmentation Tracker, CRAs): opens by itself when the page asks, from the My schedule button, and
// draws My schedule's calendar: click days (today and later) to pick them, then Save sets them as a day off or clears them.

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

const CELL = 'flex h-12 flex-col items-center justify-center gap-0.5 rounded-lg border text-sm font-semibold transition';

const LOOKS = {
    rest: ['border-sky bg-sky/20 text-sky-800', 'Rest Day'],
    leave: ['border-coral bg-coral/20 text-coral-700', 'Absent'],
    duty: ['border-line bg-white text-teal-700', 'On duty'],
    plain: ['border-line bg-white text-ink', ''],
};

const pad = (n) => String(n).padStart(2, '0');
const keyOf = (year, month, day) => `${year}-${pad(month + 1)}-${pad(day)}`;
const parts = (key) => key.split('-').map(Number);
const shortLabel = (key) => `${MONTHS[parts(key)[1] - 1].slice(0, 3)} ${parts(key)[2]}`;

function initCalendar(form) {
    const data = JSON.parse(form.dataset.calendar);
    const [fromYear, fromMonth] = parts(data.from);
    const [untilYear, untilMonth] = parts(data.until);
    const first = fromYear * 12 + fromMonth - 1;
    const last = untilYear * 12 + untilMonth - 1;
    const loggedIn = new Set(data.logged_in);
    const selected = new Set();
    let shown = first;

    const grid = form.querySelector('[data-schedule-grid]');
    const dialog = form.closest('dialog');
    const save = form.querySelector('[data-schedule-save]');
    const selectedText = form.querySelector('[data-schedule-selected]');

    function syncSelection() {
        form.querySelectorAll('input[name="dates[]"]').forEach((input) => input.remove());
        [...selected].sort().forEach((key) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'dates[]';
            input.value = key;
            form.append(input);
        });
        save.disabled = selected.size === 0;
        selectedText.innerHTML = selected.size === 0
            ? 'Pick days on the calendar'
            : `<strong>${selected.size}</strong> ${selected.size === 1 ? 'date' : 'dates'}: ${[...selected].sort().map(shortLabel).join(', ')}`;
    }

    function render() {
        const year = Math.floor(shown / 12);
        const month = shown % 12;
        grid.querySelectorAll('[data-day]').forEach((cell) => cell.remove());

        form.querySelector('[data-schedule-month]').textContent = `${MONTHS[month]} ${year}`;
        dialog.querySelector('[data-schedule-month-name]').textContent = MONTHS[month];
        form.querySelector('[data-schedule-prev]').disabled = shown <= first;
        form.querySelector('[data-schedule-next]').disabled = shown >= last;

        for (let i = new Date(year, month, 1).getDay(); i > 0; i--) {
            const blank = document.createElement('span');
            blank.dataset.day = '';
            grid.append(blank);
        }

        const counts = { rest: 0, leave: 0, duty: 0 };
        const days = new Date(year, month + 1, 0).getDate();

        for (let day = 1; day <= days; day++) {
            const key = keyOf(year, month, day);
            const off = data.days_off[key];
            const past = key < data.today;
            const look = off ?? (loggedIn.has(key) ? 'duty' : 'plain');
            if (off) counts[off]++;
            if (loggedIn.has(key)) counts.duty++;

            const [colors, tag] = LOOKS[look];
            const cell = document.createElement('button');
            cell.type = 'button';
            cell.dataset.day = key;
            cell.disabled = past;
            cell.setAttribute('aria-pressed', selected.has(key) ? 'true' : 'false');
            cell.setAttribute('aria-label', `${MONTHS[month]} ${day}${tag ? `, ${tag}` : ''}${key === data.today ? ', today' : ''}`);
            cell.className = [
                CELL,
                colors,
                past ? 'cursor-default opacity-50' : 'cursor-pointer hover:border-brand-400',
                selected.has(key) ? 'ring-2 ring-brand-500 ring-offset-1' : '',
            ].join(' ');
            cell.innerHTML = `<span>${day}</span><span class="text-[10px] leading-none font-semibold">${tag || (key === data.today ? 'Today' : '&nbsp;')}</span>`;
            if (!past) {
                cell.addEventListener('click', () => {
                    if (selected.has(key)) selected.delete(key);
                    else selected.add(key);
                    syncSelection();
                    render();
                });
            }
            grid.append(cell);
        }

        Object.entries(counts).forEach(([name, count]) => {
            dialog.querySelector(`[data-schedule-count="${name}"]`).textContent = count;
        });
    }

    form.querySelector('[data-schedule-prev]').addEventListener('click', () => {
        shown = Math.max(first, shown - 1);
        render();
    });
    form.querySelector('[data-schedule-next]').addEventListener('click', () => {
        shown = Math.min(last, shown + 1);
        render();
    });

    syncSelection();
    render();
}

export function initDutySchedule() {
    const dialog = document.getElementById('on-duty-dialog');
    if (!dialog) return;

    document.querySelectorAll('[data-schedule-open]').forEach((button) => button.addEventListener('click', () => dialog.showModal()));
    dialog.querySelectorAll('[data-on-duty-close]').forEach((button) => button.addEventListener('click', () => dialog.close()));

    const form = dialog.querySelector('[data-schedule]');
    if (form) initCalendar(form);

    if (dialog.hasAttribute('data-auto-open')) dialog.showModal();
}

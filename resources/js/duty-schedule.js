// On-duty pop-up (Segmentation Tracker, CRAs): opens by itself when the page asks, from the My schedule button, and
// draws My schedule's calendar: click days (today and later) to pick them, or pick a whole week (the arrow at a row's end),
// every one of a weekday (its heading) or the whole month at once, then Save sets them as a Rest Day or a leave, or clears them.

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

const CELL = 'flex h-12 flex-col items-center justify-center gap-0.5 rounded-lg border text-sm font-semibold transition';

const PLAIN_LOOKS = {
    duty: ['border-line bg-white text-teal-700', 'On duty'],
    plain: ['border-line bg-white text-ink', ''],
};

const WEEK_BUTTON = 'grid h-12 place-items-center rounded-lg text-muted transition hover:bg-brand-50 hover:text-brand-700 disabled:pointer-events-none disabled:opacity-0';

const pad = (n) => String(n).padStart(2, '0');
const keyOf = (year, month, day) => `${year}-${pad(month + 1)}-${pad(day)}`;
const parts = (key) => key.split('-').map(Number);
const shortLabel = (key) => `${MONTHS[parts(key)[1] - 1].slice(0, 3)} ${parts(key)[2]}`;

function initCalendar(form) {
    const data = JSON.parse(form.dataset.calendar);
    // Each day-off type's [cell colors, short name] (DutySchedule::LOOKS).
    const looks = {
        ...Object.fromEntries(Object.entries(JSON.parse(form.dataset.looks)).map(([type, look]) => [type, [`${look.border} ${look.tag}`, look.short]])),
        ...PLAIN_LOOKS,
    };
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

    // Pick every open day in keys, or unpick them all when every one is already picked.
    function toggle(keys) {
        const open = keys.filter((key) => key >= data.today && key <= data.until);
        if (open.length === 0) return;
        const all = open.every((key) => selected.has(key));
        open.forEach((key) => (all ? selected.delete(key) : selected.add(key)));
        syncSelection();
        render();
    }

    function monthKeys(filter = () => true) {
        const year = Math.floor(shown / 12);
        const month = shown % 12;
        const days = new Date(year, month + 1, 0).getDate();

        return Array.from({ length: days }, (_, i) => i + 1)
            .filter((day) => filter(new Date(year, month, day)))
            .map((day) => keyOf(year, month, day));
    }

    function render() {
        const year = Math.floor(shown / 12);
        const month = shown % 12;
        grid.querySelectorAll('[data-day]').forEach((cell) => cell.remove());

        form.querySelector('[data-schedule-month]').textContent = `${MONTHS[month]} ${year}`;
        dialog.querySelectorAll('[data-schedule-month-name]').forEach((el) => (el.textContent = MONTHS[month]));
        form.querySelector('[data-schedule-prev]').disabled = shown <= first;
        form.querySelector('[data-schedule-next]').disabled = shown >= last;

        for (let i = new Date(year, month, 1).getDay(); i > 0; i--) {
            const blank = document.createElement('span');
            blank.dataset.day = '';
            grid.append(blank);
        }

        const counts = Object.fromEntries([...Object.keys(looks), 'duty'].map((type) => [type, 0]));
        const days = new Date(year, month + 1, 0).getDate();
        let week = [];

        for (let day = 1; day <= days; day++) {
            const key = keyOf(year, month, day);
            const off = data.days_off[key];
            const past = key < data.today;
            const look = off ?? (loggedIn.has(key) ? 'duty' : 'plain');
            if (off) counts[off]++;
            if (loggedIn.has(key)) counts.duty++;

            const [colors, tag] = looks[look] ?? looks.plain;
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
            cell.innerHTML = `<span>${day}</span><span class="text-[0.625rem] leading-none font-semibold">${tag || (key === data.today ? 'Today' : '&nbsp;')}</span>`;
            if (!past) {
                cell.addEventListener('click', () => toggle([key]));
            }
            grid.append(cell);
            week.push(key);

            // The row's end: a button that picks the whole week (its open days).
            if (new Date(year, month, day).getDay() === 6 || day === days) {
                const keys = week;
                for (let i = new Date(year, month, day).getDay(); i < 6; i++) {
                    const blank = document.createElement('span');
                    blank.dataset.day = '';
                    grid.append(blank);
                }
                const pickWeek = document.createElement('button');
                pickWeek.type = 'button';
                pickWeek.dataset.day = '';
                pickWeek.className = WEEK_BUTTON;
                pickWeek.disabled = keys[keys.length - 1] < data.today;
                pickWeek.title = `Pick the week of ${shortLabel(keys[0])}`;
                pickWeek.setAttribute('aria-label', pickWeek.title);
                pickWeek.innerHTML = '<svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6"/></svg>';
                pickWeek.addEventListener('click', () => toggle(keys));
                grid.append(pickWeek);
                week = [];
            }
        }

        Object.entries(counts).forEach(([name, count]) => {
            const counter = dialog.querySelector(`[data-schedule-count="${name}"]`);
            if (!counter) return;
            counter.textContent = count;
            const row = counter.closest('[data-schedule-hide-empty]');
            if (row) row.classList.toggle('hidden', count === 0);
        });
    }

    form.querySelector('[data-schedule-pick-month]').addEventListener('click', () => toggle(monthKeys()));
    form.querySelector('[data-schedule-pick-none]').addEventListener('click', () => {
        selected.clear();
        syncSelection();
        render();
    });
    form.querySelectorAll('[data-schedule-weekday]').forEach((button) => button.addEventListener('click', () => {
        const weekday = Number(button.dataset.scheduleWeekday);
        toggle(monthKeys((date) => date.getDay() === weekday));
    }));

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

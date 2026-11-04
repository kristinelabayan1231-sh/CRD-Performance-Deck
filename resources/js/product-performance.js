// Product Performance: Ranking and Graph & insights slide left / right with ‹ ›, the graph shows sales, rank or
// retention, the ranking sorts by sales or retention, and a product's chip shows or hides its line on every graph.
export function initProductPerformance() {
    initSlider();
    initTrend();
    initSwitch('[data-pp-rank-by]', 'ppRankBy', (by) => {
        document.querySelectorAll('[data-pp-ranking]').forEach((body) => (body.hidden = body.dataset.ppRanking !== by));
    });
    initSwitch('[data-pp-graph]', 'ppGraph', (mode) => {
        document.querySelectorAll('[data-pp-graph-head]').forEach((head) => (head.hidden = head.dataset.ppGraphHead !== mode));
        document.querySelectorAll('[data-pp-graph-body]').forEach((body) => {
            if (body.dataset.ppGraphBody === mode) body.removeAttribute('display');
            else body.setAttribute('display', 'none');
        });
    });

    const chips = document.querySelector('[data-pp-chart]');
    if (!chips) return;

    chips.addEventListener('click', (event) => {
        const chip = event.target.closest('[data-pp-toggle]');
        if (!chip) return;

        const shown = chip.getAttribute('aria-pressed') !== 'true';
        chip.setAttribute('aria-pressed', String(shown));
        document.querySelectorAll(`[data-pp-line="${chip.dataset.ppToggle}"]`).forEach((line) => {
            if (shown) line.removeAttribute('display');
            else line.setAttribute('display', 'none');
        });
    });
}

// A group of buttons where one is pressed; show(value) applies the choice.
function initSwitch(selector, key, show) {
    const buttons = [...document.querySelectorAll(selector)];
    buttons.forEach((button) =>
        button.addEventListener('click', () => {
            buttons.forEach((other) => other.setAttribute('aria-pressed', String(other === button)));
            show(button.dataset[key]);
        }),
    );
}

// Colourblind-checked line colours, per theme, in this order. A product keeps its colour while it's on the graph.
const TREND_COLORS = {
    light: ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300'],
    dark: ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#2f9e44'],
};
const TREND_DEFAULT_PRODUCTS = 4;
const TREND_NOTES = {
    day: 'past 30 days',
    week: 'past 12 weeks, Monday–Sunday',
    month: 'past 12 months',
};
const TREND_SLOTS = { day: 30, week: 12, month: 12 };
// A view needs this many finished slots to draw a line; a tab with less opens on the next view that has them.
const TREND_MIN_FINISHED = 3;
const SVG_NS = 'http://www.w3.org/2000/svg';

const svg = (name, attributes = {}, parent = null) => {
    const node = document.createElementNS(SVG_NS, name);
    Object.entries(attributes).forEach(([key, value]) => node.setAttribute(key, value));
    parent?.append(node);
    return node;
};

const html = (name, className = '', text = '', parent = null) => {
    const node = document.createElement(name);
    node.className = className;
    node.textContent = text;
    parent?.append(node);
    return node;
};

const number = (value) => Math.round(value).toLocaleString('en-PH');
const formats = {
    sales: { full: (value) => `₱${number(value)}`, axis: (value) => (value >= 1e6 ? `₱${+(value / 1e6).toFixed(2)}M` : value >= 1e3 ? `₱${+(value / 1e3).toFixed(1)}K` : `₱${number(value)}`) },
    orders: { full: number, axis: number },
    rts: { full: (value) => `${value.toFixed(1)}%`, axis: (value) => `${+value.toFixed(1)}%` },
};

// The smallest round top and step that fit $max in about four gridlines.
function niceScale(max, wholeNumbers) {
    if (max <= 0) return { top: wholeNumbers ? 4 : 1, step: wholeNumbers ? 1 : 0.25 };
    const rough = max / 4;
    const power = 10 ** Math.floor(Math.log10(rough));
    let step = [1, 2, 2.5, 5, 10].map((factor) => factor * power).find((candidate) => candidate >= rough);
    if (wholeNumbers) step = Math.max(1, Math.ceil(step));
    return { top: Math.ceil(max / step) * step, step };
}

// Per tab: the past days, weeks and months as lines (the tab's total and up to six products), by gross sales, orders or
// RTS. A hover shows every line's figure for that slot; a hollow dot is a slot that isn't over yet.
function initTrend() {
    const panel = document.querySelector('[data-pp-trend]');
    if (!panel) return;

    const plot = panel.querySelector('[data-pp-trend-plot]');
    const chips = panel.querySelector('[data-pp-trend-chips]');
    const headline = panel.querySelector('[data-pp-trend-headline]');
    const note = panel.querySelector('[data-pp-trend-note]');
    const table = panel.querySelector('[data-pp-trend-table]');
    const allName = panel.dataset.ppTrendAll;
    const status = (text) => {
        plot.replaceChildren(html('p', 'py-24 text-center text-sm text-muted', text));
    };

    let data = null;
    let view = panel.querySelector('[data-pp-trend-view][aria-pressed="true"]').dataset.ppTrendView;
    let metric = 'sales';
    let showAll = true;
    // Product name => its colour's place in TREND_COLORS.
    const shown = new Map();

    const colorOf = (name) => TREND_COLORS[document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light'][shown.get(name)];

    const drawChips = (current) => {
        const chip = (name, pressed, color, disabled, onClick) => {
            const button = html('button', 'flex items-center gap-1.5 rounded-full border border-line px-2.5 py-1 text-xs font-medium text-muted transition hover:border-brand-400 disabled:opacity-50 aria-pressed:bg-white aria-pressed:text-ink aria-[pressed=false]:border-dashed', '', chips);
            button.type = 'button';
            button.disabled = disabled;
            button.setAttribute('aria-pressed', String(pressed));
            if (disabled) button.title = `Up to ${TREND_COLORS.light.length} products at a time`;
            const dot = html('span', 'size-2.5 rounded-full border border-line', '', button);
            if (color) dot.style.background = color;
            else if (pressed) dot.classList.add('bg-current');
            button.append(name);
            button.addEventListener('click', onClick);
        };

        chips.replaceChildren();
        chip(allName, showAll, null, false, () => {
            showAll = !showAll;
            draw();
        });
        current.products.forEach((product) => {
            const on = shown.has(product.name);
            chip(product.name, on, on ? colorOf(product.name) : null, !on && shown.size >= TREND_COLORS.light.length, () => {
                if (on) {
                    shown.delete(product.name);
                } else {
                    const taken = [...shown.values()];
                    shown.set(product.name, TREND_COLORS.light.findIndex((_, place) => !taken.includes(place)));
                }
                draw();
            });
        });
    };

    // The last finished slot against the one before it, and the running one so far.
    const drawHeadline = (current) => {
        const format = formats[metric].full;
        const values = current.total[metric];
        const last = values.length - 1;
        const done = current.partial ? last - 1 : last;
        const name = (i) => current.labels[i];
        headline.replaceChildren();
        if (done >= 0 && values[done] !== null) {
            headline.append(`${name(done)}: ${format(values[done])}`);
            const before = values[done - 1];
            if (metric !== 'rts' && before > 0) {
                const change = Math.round(((values[done] - before) / before) * 100);
                html('span', `ml-2 ${change >= 0 ? 'text-teal-700' : 'text-coral-700'}`, `${change >= 0 ? '▲' : '▼'} ${Math.abs(change)}% vs ${name(done - 1)}`, headline);
            } else if (metric === 'rts' && before !== null && before !== undefined) {
                html('span', 'ml-2 font-normal text-muted', `${format(before)} in ${name(done - 1)}`, headline);
            }
        }
        if (current.partial && values[last] !== null) {
            html('span', 'ml-3 font-normal text-muted', `${name(last)} so far: ${format(values[last])}`, headline);
        }
    };

    const drawTable = (current, series) => {
        const element = html('table', 'w-full min-w-[32rem] tabular-nums');
        const head = html('tr', 'border-b border-line text-[0.6875rem] tracking-wide text-muted uppercase', '', html('thead', '', '', element));
        html('th', 'px-2.5 py-2 text-left font-semibold', view === 'day' ? 'Day' : view === 'week' ? 'Week' : 'Month', head).scope = 'col';
        series.forEach((line) => (html('th', 'px-2.5 py-2 text-right font-semibold', line.name, head).scope = 'col'));
        const body = html('tbody', 'divide-y divide-line', '', element);
        current.labels.map((_, i) => i).reverse().forEach((i) => {
            const row = html('tr', '', '', body);
            const partial = current.partial && i === current.labels.length - 1;
            html('td', 'px-2.5 py-1.5 font-semibold whitespace-nowrap', `${current.labels[i]}${partial ? ' (so far)' : ''}`, row);
            series.forEach((line) => html('td', 'px-2.5 py-1.5 text-right', line.values[i] === null ? '—' : formats[metric].full(line.values[i]), row));
        });
        table.replaceChildren(element);
    };

    const drawPlot = (current, series) => {
        const count = current.labels.length;
        const width = Math.max(plot.clientWidth, 280);
        const height = width < 640 ? 260 : 380;
        const labelled = series.length <= 4 && width >= 640;
        const margin = { top: 14, right: labelled ? 150 : 16, bottom: 30, left: 62 };
        const innerWidth = width - margin.left - margin.right;
        const innerHeight = height - margin.top - margin.bottom;
        const peak = Math.max(0, ...series.flatMap((line) => line.values.filter((value) => value !== null)));
        const { top, step } = niceScale(peak, metric === 'orders');
        const x = (i) => margin.left + (count > 1 ? (i / (count - 1)) * innerWidth : innerWidth / 2);
        const y = (value) => margin.top + innerHeight - (value / top) * innerHeight;

        const root = svg('svg', { viewBox: `0 0 ${width} ${height}`, width, height, class: 'block max-w-full', role: 'img', 'aria-label': `${series.map((line) => line.name).join(', ')}: ${TREND_NOTES[view]}` });
        const axisText = { 'font-size': 12, fill: 'currentColor', class: 'text-muted' };
        for (let tick = 0; tick <= top + step / 2; tick += step) {
            svg('line', { x1: margin.left, x2: margin.left + innerWidth, y1: y(tick), y2: y(tick), stroke: 'currentColor', 'stroke-opacity': 0.18, class: 'text-muted' }, root);
            svg('text', { x: margin.left - 8, y: y(tick) + 4, 'text-anchor': 'end', ...axisText }, root).textContent = formats[metric].axis(tick);
        }
        const every = Math.max(1, Math.ceil(count / Math.max(2, Math.floor(innerWidth / 84))));
        current.labels.forEach((label, i) => {
            if ((count - 1 - i) % every !== 0) return;
            const anchor = count === 1 ? 'middle' : i === count - 1 ? 'end' : i === 0 ? 'start' : 'middle';
            svg('text', { x: x(i), y: height - 8, 'text-anchor': anchor, ...axisText }, root).textContent = label.replace('Week of ', '');
        });

        const lastDone = current.partial ? count - 2 : count - 1;
        series.forEach((line) => {
            const stroke = { fill: 'none', stroke: line.color ?? 'currentColor', 'stroke-width': line.color ? 2 : 3, 'stroke-linejoin': 'round', 'stroke-linecap': 'round', class: line.color ? '' : 'text-ink' };
            const dot = { r: count <= 14 ? 3.5 : 2.5, fill: line.color ?? 'currentColor', class: line.color ? '' : 'text-ink' };
            let run = [];
            const flush = () => {
                if (run.length > 1) svg('polyline', { ...stroke, points: run.join(' ') }, root);
                if (run.length === 1) svg('circle', { ...dot, cx: run[0].split(',')[0], cy: run[0].split(',')[1] }, root);
                run = [];
            };
            line.values.forEach((value, i) => {
                if (value === null || i > lastDone) return flush();
                run.push(`${x(i)},${y(value)}`);
                if (count <= 14) svg('circle', { ...dot, cx: x(i), cy: y(value) }, root);
            });
            flush();
            // The slot that isn't over yet: a hollow dot with no line to it, so it doesn't read as a drop.
            if (current.partial && line.values[count - 1] !== null) {
                svg('circle', { r: 4, fill: 'none', stroke: stroke.stroke, 'stroke-width': 2, class: stroke.class, cx: x(count - 1), cy: y(line.values[count - 1]) }, root);
            }
        });

        // Names beside the lines' ends when there's room for them, spaced so they don't overlap.
        if (labelled) {
            const ends = series
                .map((line) => {
                    const i = line.values.findLastIndex((value) => value !== null);
                    return i < 0 ? null : { line, i, at: y(line.values[i]) + 4 };
                })
                .filter(Boolean)
                .sort((a, b) => a.at - b.at);
            ends.forEach((end, place) => {
                if (place > 0) end.at = Math.max(end.at, ends[place - 1].at + 16);
                const name = end.line.name.length > 21 ? `${end.line.name.slice(0, 20)}…` : end.line.name;
                svg('text', { x: margin.left + innerWidth + 10, y: end.at, 'font-size': 12, 'font-weight': end.line.color ? 500 : 700, fill: 'currentColor', class: 'text-ink' }, root).textContent = name;
            });
        }

        const crosshair = svg('line', { y1: margin.top, y2: margin.top + innerHeight, stroke: 'currentColor', 'stroke-dasharray': '3 3', class: 'text-muted', visibility: 'hidden' }, root);
        const markers = series.map((line) => svg('circle', { r: 5, fill: line.color ?? 'currentColor', stroke: 'white', 'stroke-width': 2, class: line.color ? '' : 'text-ink', visibility: 'hidden' }, root));
        const tooltip = html('div', 'pointer-events-none absolute z-10 hidden w-max max-w-64 rounded-lg border border-line bg-white p-2.5 text-xs tabular-nums shadow-lg');
        const hover = svg('rect', { x: margin.left - 8, y: margin.top, width: innerWidth + 16, height: innerHeight, fill: 'transparent' }, root);

        const hide = () => {
            tooltip.classList.add('hidden');
            crosshair.setAttribute('visibility', 'hidden');
            markers.forEach((marker) => marker.setAttribute('visibility', 'hidden'));
        };
        hover.addEventListener('pointerleave', hide);
        hover.addEventListener('pointermove', (event) => {
            const box = root.getBoundingClientRect();
            const scale = width / box.width;
            const position = ((event.clientX - box.left) * scale - margin.left) / (innerWidth || 1);
            const i = count > 1 ? Math.min(count - 1, Math.max(0, Math.round(position * (count - 1)))) : 0;
            crosshair.setAttribute('x1', x(i));
            crosshair.setAttribute('x2', x(i));
            crosshair.setAttribute('visibility', 'visible');
            series.forEach((line, place) => {
                const value = line.values[i];
                markers[place].setAttribute('visibility', value === null ? 'hidden' : 'visible');
                if (value !== null) {
                    markers[place].setAttribute('cx', x(i));
                    markers[place].setAttribute('cy', y(value));
                }
            });

            const partial = current.partial && i === count - 1;
            tooltip.replaceChildren(html('p', 'mb-1 font-semibold text-ink', `${current.labels[i]}${partial ? ' (so far)' : ''}`));
            [...series]
                .filter((line) => line.values[i] !== null)
                .sort((a, b) => b.values[i] - a.values[i])
                .forEach((line) => {
                    const row = html('p', 'flex items-center gap-1.5 text-muted', '', tooltip);
                    const swatch = html('span', 'size-2 shrink-0 rounded-full', '', row);
                    if (line.color) swatch.style.background = line.color;
                    else swatch.classList.add('bg-ink');
                    html('span', 'truncate', line.name, row);
                    html('span', 'ml-auto pl-3 font-semibold text-ink', formats[metric].full(line.values[i]), row);
                });
            tooltip.classList.remove('hidden');
            const left = (x(i) / scale);
            tooltip.style.left = `${left + tooltip.offsetWidth + 16 > box.width ? left - tooltip.offsetWidth - 12 : left + 12}px`;
            tooltip.style.top = `${margin.top}px`;
        });

        plot.replaceChildren(root, tooltip);
    };

    function draw() {
        const current = data[view];
        const startsLate = current.labels.length > 0 && current.labels.length < TREND_SLOTS[view];
        note.textContent = `${TREND_NOTES[view].charAt(0).toUpperCase()}${TREND_NOTES[view].slice(1)}, up to ${panel.dataset.ppTrendEnd}${startsLate ? ` · first orders: ${current.labels[0].replace('Week of ', 'week of ')}` : ''}${current.partial ? ' · hollow dot = not over yet' : ''}${metric === 'rts' ? ' · recent dates read low until parcels arrive or come back' : ''}`;
        if (current.labels.length === 0) {
            chips.replaceChildren();
            headline.replaceChildren();
            table.replaceChildren();
            status(panel.dataset.ppTrendEmpty);
            return;
        }

        drawChips(current);
        const series = [
            ...(showAll ? [{ name: allName, color: null, values: current.total[metric] }] : []),
            ...current.products.filter((product) => shown.has(product.name)).map((product) => ({ name: product.name, color: colorOf(product.name), values: product[metric] })),
        ];
        drawHeadline(current);
        drawTable(current, series);
        if (series.length === 0) {
            status('Pick a line above to show it.');
            return;
        }
        drawPlot(current, series);
    }

    initSwitch('[data-pp-trend-view]', 'ppTrendView', (value) => {
        view = value;
        if (data) draw();
    });
    initSwitch('[data-pp-trend-metric]', 'ppTrendMetric', (value) => {
        metric = value;
        if (data) draw();
    });

    fetch(panel.dataset.ppTrend, { headers: { Accept: 'application/json' } })
        .then((response) => (response.ok ? response.json() : Promise.reject(new Error(String(response.status)))))
        .then((loaded) => {
            data = loaded;
            const finished = (candidate) => candidate.labels.length - (candidate.partial ? 1 : 0);
            if (finished(data[view]) < TREND_MIN_FINISHED) {
                view = ['month', 'week', 'day'].find((candidate) => finished(data[candidate]) >= TREND_MIN_FINISHED) ?? view;
                panel.querySelectorAll('[data-pp-trend-view]').forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.ppTrendView === view)));
            }
            // The top sellers of the widest view with orders start on the graph.
            const start = [data[view], data.month, data.week, data.day].find((candidate) => candidate.products.length > 0);
            start?.products.slice(0, TREND_DEFAULT_PRODUCTS).forEach((product, place) => shown.set(product.name, place));
            draw();

            let width = plot.clientWidth;
            new ResizeObserver(() => {
                if (plot.clientWidth !== width) {
                    width = plot.clientWidth;
                    draw();
                }
            }).observe(plot);
            new MutationObserver(draw).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
        })
        .catch(() => status('The graph could not be loaded. Refresh the page to try again.'));
}

// One panel at a time; ‹ › (or ← →, or a swipe) slide to the other, the box fitting the panel's height.
// Always opens on the Ranking.
function initSlider() {
    const slider = document.querySelector('[data-pp-slider]');
    if (!slider) return;

    const viewport = slider.querySelector('[data-pp-viewport]');
    const track = slider.querySelector('[data-pp-track]');
    const panels = [...slider.querySelectorAll('[data-pp-panel]')];
    const note = slider.querySelector('[data-pp-slide-note]');
    let current = 0;

    const fit = () => (viewport.style.height = `${panels[current].offsetHeight}px`);
    const go = (index) => {
        current = (index + panels.length) % panels.length;
        track.style.transform = `translateX(-${current * 100}%)`;
        panels.forEach((panel, i) => {
            panel.inert = i !== current;
            panel.setAttribute('aria-hidden', String(i !== current));
        });
        note.textContent = current === 0 ? 'Slide to see graph and insights' : 'Slide back to see the ranking';
        fit();
    };

    slider.querySelector('[data-pp-slide="prev"]').addEventListener('click', () => go(current - 1));
    slider.querySelector('[data-pp-slide="next"]').addEventListener('click', () => go(current + 1));
    document.addEventListener('keydown', (event) => {
        if (event.target.closest('input, select, textarea') || event.altKey || event.metaKey || event.ctrlKey) return;
        if (event.key === 'ArrowRight') go(current + 1);
        if (event.key === 'ArrowLeft') go(current - 1);
    });

    let startX = null;
    let startY = null;
    viewport.addEventListener('touchstart', (event) => ([startX, startY] = [event.touches[0].clientX, event.touches[0].clientY]), { passive: true });
    viewport.addEventListener('touchend', (event) => {
        if (startX === null) return;
        const dx = event.changedTouches[0].clientX - startX;
        const dy = event.changedTouches[0].clientY - startY;
        if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) go(current + (dx < 0 ? 1 : -1));
        startX = null;
    });
    const resized = new ResizeObserver(fit);
    panels.forEach((panel) => resized.observe(panel));

    go(0);
}

/**
 * Presentation Board: pointing at a month marks it on every chart and shows its numbers in each chart's read-out;
 * leaving the chart puts the read-outs back. The mark's colour follows the Light / Dark theme (--pb-mark).
 */

export function initPresentationBoard() {
    const board = document.querySelector('[data-presentation-board]');

    if (!board) {
        return;
    }

    const show = (element, read) => {
        const target = document.getElementById(element.dataset.readTarget);

        if (target) {
            target.textContent = read;
        }
    };

    const clear = () => {
        board.querySelectorAll('[data-point]').forEach((element) => {
            element.style.background = 'transparent';
        });
        board.querySelectorAll('[data-read-default]').forEach((target) => {
            target.textContent = target.dataset.readDefault;
        });
    };

    const point = (event) => {
        const element = event.target.closest('[data-point]');

        if (!element) {
            return;
        }

        clear();
        const mark = getComputedStyle(board).getPropertyValue('--pb-mark').trim() || 'rgb(255 255 255 / .08)';
        board.querySelectorAll(`[data-point="${element.dataset.point}"]`).forEach((match) => {
            match.style.background = mark;
            show(match, match.dataset.read);
        });
    };

    board.addEventListener('mouseover', point);
    board.addEventListener('focusin', point);
    board.querySelectorAll('[data-chart]').forEach((chart) => chart.addEventListener('mouseleave', clear));

    initProductSort(board);
    initCraCompare(board);
    initSideBySide(board);
}

/**
 * Top products: sort by sales or by upsold % (the section's data-sort shows that view's bars, pills and lines; Others
 * stays after the products, TSD Product goes last by sales and first by upsold %), a product with nothing to show
 * doesn't open, and Breakdown opens the main vs upsell popup.
 */
function initProductSort(board) {
    const rows = board.querySelector('[data-product-rows]');

    if (!rows) {
        return;
    }

    const dialog = board.querySelector('[data-upsell-dialog]');
    board.querySelector('[data-upsell-open]')?.addEventListener('click', () => dialog?.showModal());
    // A click on the backdrop closes it, like Esc.
    dialog?.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });

    const section = board.querySelector('[data-products-section]');

    rows.querySelectorAll('details[data-empty] > summary').forEach((summary) => {
        summary.addEventListener('click', (event) => event.preventDefault());
    });

    const buttons = board.querySelectorAll('[data-product-sort]');

    buttons.forEach((button) => button.addEventListener('click', () => {
        const by = button.dataset.productSort;
        const others = rows.querySelector('[data-others]');

        // The pressed look comes from the board's stylesheet ([data-product-sort][aria-pressed]), so it follows the theme.
        buttons.forEach((other) => other.setAttribute('aria-pressed', String(other === button)));
        if (section) section.dataset.sort = by;

        [...rows.querySelectorAll('[data-product]')]
            .sort((a, b) => (Number(b.dataset[by]) - Number(a.dataset[by])) || (Number(b.dataset.sales) - Number(a.dataset.sales)))
            .forEach((row, index) => {
                const rank = row.querySelector('[data-rank]');
                rank.textContent = index + 1;
                rank.dataset.place = index + 1;
                rows.insertBefore(row, others);
            });

        const tsd = rows.querySelector('[data-tsd]');
        if (tsd) {
            if (by === 'upsell') rows.prepend(tsd);
            else rows.append(tsd);
        }
    }));
}

/**
 * CRA cards: pointing at (or tabbing to) a month's dot shows that month vs the one before in the card's table, and its
 * month name in bold; leaving the dot puts the default comparison back.
 */
function initCraCompare(board) {
    board.querySelectorAll('[data-cra-card]').forEach((card) => {
        const compare = (key) => {
            card.querySelectorAll('[data-cra-compare]').forEach((table) => {
                table.hidden = table.dataset.craCompare !== key;
            });
            card.querySelectorAll('[data-cra-axis]').forEach((label) => {
                label.style.fontWeight = label.dataset.craAxis === key ? '700' : '';
            });
        };

        card.querySelectorAll('[data-cra-dot]').forEach((dot) => {
            const enter = () => compare(dot.dataset.craDot);
            const leave = () => compare('default');
            dot.addEventListener('mouseenter', enter);
            dot.addEventListener('focus', enter);
            dot.addEventListener('mouseleave', leave);
            dot.addEventListener('blur', leave);
        });
    });
}

/**
 * Side by side: "Every month" shows each month as a column; "Two months" shows the two months picked with the change
 * between them, worked out from the figures the page carries (data-side-data). Chip colours follow the theme.
 */
function initSideBySide(board) {
    const section = board.querySelector('[data-side-by-side]');

    if (!section) {
        return;
    }

    const data = JSON.parse(section.querySelector('[data-side-data]').textContent);
    const pickA = section.querySelector('[data-side-a]');
    const pickB = section.querySelector('[data-side-b]');
    const rows = section.querySelector('[data-side-rows]');

    const cell = (tag, text, style = '') => {
        const element = document.createElement(tag);
        element.textContent = text;
        element.style.cssText = style;

        return element;
    };

    const change = (figure, before, now) => {
        if (before === null || now === null || (!figure.rate && !before)) {
            return { text: '—', style: 'color: var(--pb-muted)' };
        }

        const amount = figure.rate ? (now - before) * 100 : (now - before) / Math.abs(before) * 100;
        const up = amount > 0.05;
        const down = amount < -0.05;
        const good = !figure.neutral && (figure.lower ? down : up);
        const bad = !figure.neutral && (figure.lower ? up : down);
        const colours = good ? 'background: var(--pb-good-bg); color: var(--pb-good)' : (bad ? 'background: var(--pb-bad-bg); color: var(--pb-bad)' : 'background: var(--pb-chip); color: var(--pb-soft)');

        return {
            text: `${up ? '▲ +' : (down ? '▼ −' : '')}${Math.abs(amount).toFixed(1)}${figure.rate ? ' pts' : '%'}`,
            style: `display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; font-weight: 700; ${colours}`,
        };
    };

    const render = () => {
        const a = Number(pickA.value);
        const b = Number(pickB.value);
        section.querySelector('[data-side-a-name]').textContent = data.months[a];
        section.querySelector('[data-side-b-name]').textContent = data.months[b];
        rows.replaceChildren();

        data.figures.forEach((figure) => {
            const row = document.createElement('tr');

            if (figure.group) {
                const heading = cell('th', figure.group, 'text-align: left; padding: 12px 10px 4px; font-size: 11px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: var(--pb-muted)');
                heading.colSpan = 4;
                heading.scope = 'colgroup';
                row.append(heading);
            } else {
                const before = figure.cells[a];
                const now = figure.cells[b];
                const chip = change(figure, before.value, now.value);
                const changeCell = cell('td', '', 'padding: 8px 10px; text-align: right');
                changeCell.append(cell('span', chip.text, chip.style));
                row.style.borderBottom = '1px solid var(--pb-row)';
                row.append(
                    cell('th', figure.label, 'text-align: left; padding: 8px 10px; font-weight: 600'),
                    cell('td', before.text ?? (figure.later ? 'Not tracked yet' : '—'), 'padding: 8px 10px; text-align: right; color: var(--pb-soft)'),
                    cell('td', now.text ?? (figure.later ? 'Not tracked yet' : '—'), 'padding: 8px 10px; text-align: right; font-weight: 700'),
                    changeCell,
                );
                row.firstChild.scope = 'row';
            }

            rows.append(row);
        });
    };

    section.querySelectorAll('[data-side-mode]').forEach((button) => button.addEventListener('click', () => {
        const mode = button.dataset.sideMode;
        section.querySelectorAll('[data-side-mode]').forEach((other) => other.setAttribute('aria-pressed', String(other === button)));
        section.querySelectorAll('[data-side-view]').forEach((view) => {
            view.hidden = view.dataset.sideView !== mode;
        });
        section.querySelector('[data-side-pick]').hidden = mode !== 'two';
    }));
    pickA.addEventListener('change', render);
    pickB.addEventListener('change', render);
    render();
}

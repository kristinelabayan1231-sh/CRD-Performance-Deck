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
}

/**
 * Top products: sort by sales or by upsell % (Others stays last), and a product with nothing to show doesn't open.
 */
function initProductSort(board) {
    const rows = board.querySelector('[data-product-rows]');

    if (!rows) {
        return;
    }

    rows.querySelectorAll('details[data-empty] > summary').forEach((summary) => {
        summary.addEventListener('click', (event) => event.preventDefault());
    });

    const buttons = board.querySelectorAll('[data-product-sort]');

    buttons.forEach((button) => button.addEventListener('click', () => {
        const by = button.dataset.productSort;
        const others = rows.querySelector('[data-others]');

        // The pressed look comes from the board's stylesheet ([data-product-sort][aria-pressed]), so it follows the theme.
        buttons.forEach((other) => other.setAttribute('aria-pressed', String(other === button)));

        [...rows.querySelectorAll('[data-product]')]
            .sort((a, b) => (Number(b.dataset[by]) - Number(a.dataset[by])) || (Number(b.dataset.sales) - Number(a.dataset.sales)))
            .forEach((row, index) => {
                row.querySelector('[data-rank]').textContent = index + 1;
                rows.insertBefore(row, others);
            });
    }));
}

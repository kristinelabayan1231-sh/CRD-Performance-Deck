/**
 * Presentation Board: pointing at a month marks it on every chart and shows its numbers in each chart's read-out;
 * leaving the chart puts the read-outs back.
 */
const MARK = 'rgb(255 255 255 / .08)';

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
        board.querySelectorAll(`[data-point="${element.dataset.point}"]`).forEach((match) => {
            match.style.background = MARK;
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

        buttons.forEach((other) => {
            const on = other === button;
            other.setAttribute('aria-pressed', String(on));
            other.style.background = on ? 'rgb(195 155 255 / .25)' : 'transparent';
            other.style.fontWeight = on ? '600' : '400';
            other.style.color = on ? '#f1eef8' : '#bdb6cf';
        });

        [...rows.querySelectorAll('[data-product]')]
            .sort((a, b) => (Number(b.dataset[by]) - Number(a.dataset[by])) || (Number(b.dataset.sales) - Number(a.dataset.sales)))
            .forEach((row, index) => {
                row.querySelector('[data-rank]').textContent = index + 1;
                rows.insertBefore(row, others);
            });
    }));
}

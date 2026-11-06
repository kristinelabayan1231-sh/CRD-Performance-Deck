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
}

// Product Performance: Ranking and Graph & insights slide left / right with ‹ ›, the graph shows sales, rank or
// retention, the ranking sorts by sales or retention, and a product's chip shows or hides its line on every graph.
export function initProductPerformance() {
    initSlider();
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

// One panel at a time; ‹ › (or ← →, or a swipe) slide to the other, the box fitting the panel's height.
// The panel last seen is remembered on this browser only.
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
        try {
            localStorage.setItem('pp-slide', String(current));
        } catch {}
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

    let saved = 0;
    try {
        saved = Math.min(panels.length - 1, Number(localStorage.getItem('pp-slide')) || 0);
    } catch {}
    go(saved);
}

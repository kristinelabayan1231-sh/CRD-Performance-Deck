// Instant hover tooltips for charts. SVG <title> tooltips are slow or missing in
// some browsers, so each <title> inside an SVG becomes its parent's data-tip and
// is shown in one floating box that follows the pointer.
export function initTooltips() {
    document.querySelectorAll('svg title').forEach((title) => {
        const target = title.parentElement;
        if (!target || target.tagName.toLowerCase() === 'svg') return;
        target.dataset.tip = title.textContent.trim();
        target.setAttribute('aria-label', target.dataset.tip);
        title.remove();
    });

    const tip = document.createElement('div');
    tip.className =
        'pointer-events-none fixed z-50 max-w-xs rounded-lg bg-ink px-2.5 py-1.5 text-xs leading-relaxed whitespace-pre-line text-white shadow-lg';
    tip.hidden = true;
    tip.setAttribute('role', 'tooltip');
    document.body.appendChild(tip);

    document.addEventListener('pointermove', (event) => {
        const target = event.target instanceof Element ? event.target.closest('[data-tip]') : null;
        if (!target) {
            tip.hidden = true;
            return;
        }
        tip.textContent = target.dataset.tip;
        tip.hidden = false;
        const gap = 14;
        const { offsetWidth: w, offsetHeight: h } = tip;
        let x = event.clientX + gap;
        let y = event.clientY + gap;
        if (x + w > window.innerWidth - 8) x = event.clientX - w - gap;
        if (y + h > window.innerHeight - 8) y = event.clientY - h - gap;
        tip.style.left = `${x}px`;
        tip.style.top = `${y}px`;
    });
    document.addEventListener('pointerleave', () => (tip.hidden = true));
    window.addEventListener('scroll', () => (tip.hidden = true), { passive: true });
}

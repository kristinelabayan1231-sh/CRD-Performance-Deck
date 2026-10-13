// Dashboard Results: the header's graph button turns every tile over to its graph, one after another,
// and back. The choice is remembered on this browser, so it stays after the dashboard's live reloads.
const KEY = 'dashboard.results.graphs';
const STAGGER_MS = 70;

export function initResultsFlip() {
    const button = document.querySelector('[data-results-graphs]');
    if (!button) return;
    const cards = [...document.querySelectorAll('[data-results-flip]')];

    const show = (graphs, animate) => {
        cards.forEach((card, i) => {
            card.style.transitionDelay = animate ? `${i * STAGGER_MS}ms` : '';
            if (!animate) card.style.transition = 'none';
            card.toggleAttribute('data-flipped', graphs);
            // Only the face in view can be clicked or tabbed to.
            card.querySelector('[data-flip-face="front"]').inert = graphs;
            card.querySelector('[data-flip-face="back"]').inert = !graphs;
            if (!animate) {
                card.offsetWidth; // apply the flip before turning the animation back on
                card.style.transition = '';
            }
        });
        button.setAttribute('aria-pressed', String(graphs));
        button.title = button.ariaLabel = graphs ? 'Show as tiles' : 'Show as graphs';
        button.querySelector('[data-graphs-icon="chart"]').hidden = graphs;
        button.querySelector('[data-graphs-icon="tiles"]').hidden = !graphs;
    };

    let graphs = false;
    try {
        graphs = localStorage.getItem(KEY) === '1';
    } catch {
        // Storage blocked: start on the tiles.
    }
    if (graphs) show(true, false);

    button.addEventListener('click', () => {
        graphs = !graphs;
        show(graphs, true);
        try {
            localStorage.setItem(KEY, graphs ? '1' : '0');
        } catch {
            // Not remembered; it still flips.
        }
    });
}

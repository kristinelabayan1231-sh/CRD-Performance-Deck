// Takes the annotated screenshots for the user guide.
// Runs against the DEMO database (crd_demo) only — see docs/user-guide/README.md.
//
//   node docs/user-guide/capture.mjs            # all shots
//   node docs/user-guide/capture.mjs weekly     # only shots whose name contains "weekly"
//
// Each shot: log in as a user, open a page, optionally interact, then draw red boxes,
// numbered badges and arrows on the targets and save screenshots/<name>.png.

import { chromium } from 'playwright';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const BASE = process.env.GUIDE_BASE_URL ?? 'http://127.0.0.1:8123';
const OUT = path.join(path.dirname(fileURLToPath(import.meta.url)), 'screenshots');
const OWNER = 'kristinelabayan1231@gmail.com';
const SUPERVISOR = 'mia.torres@example.com';
const CRA = 'anna.cruz@example.com';

// marks: [selector, side ('left' | 'right' | 'top' | 'bottom'), nth?]
const shots = [
    { name: '01-login', as: null, url: '/login', marks: [['form button[type=submit]', 'left'], ['form label', 'left'], ['.lg\\:flex.relative, div.relative.hidden', 'left']] },

    { name: '02-dashboard', as: OWNER, url: '/dashboard', marks: [['[role=tablist]', 'left'], ['[data-tab-panel=today] > div:first-child', 'left'], ['[data-tab-panel=today] figure', 'left'], ['[data-tab-panel=today] figure', 'bottom', 1], ['[data-tab-panel=today] .lg\\:col-span-4', 'bottom']] },

    { name: '03-daily-overview', as: OWNER, url: '/segmentation', marks: [['nav[aria-label="Segmentation Tracker sections"]', 'left'], ['main > div:first-child > div:last-child', 'left'], ['form[action$="/segmentation"]', 'left'], ['[data-live-summary]', 'left']] },
    { name: '04-daily-table', as: OWNER, url: '/segmentation', scroll: '[data-column-picker]', marks: [['[data-column-picker] summary', 'left'], ['tbody tr:first-child [data-note-cell]', 'left'], ['thead th:nth-child(6)', 'top']] },
    { name: '04b-daily-fields', as: OWNER, url: '/segmentation', scroll: '[data-column-picker]', hscroll: 'tbody tr:first-child select[name=customer_tag]', marks: [['tbody tr:first-child select[name=status]', 'bottom'], ['tbody tr:first-child select[name=repeat_purchase]', 'bottom'], ['tbody tr:first-child select[name=customer_tag]', 'bottom'], ['th[data-col=repeat_purchase]', 'top']] },
    { name: '05-per-cra-popup', as: OWNER, url: '/segmentation', prep: async (p) => { await p.click('[data-per-cra-open]'); await p.waitForTimeout(600); }, marks: [['#per-cra-dialog [data-per-cra-list]', 'left']] },
    { name: '06-column-picker', as: OWNER, url: '/segmentation', scroll: '[data-column-picker]', prep: async (p) => { await p.click('[data-column-picker] summary'); }, marks: [['[data-column-picker] > div', 'left']] },
    { name: '07-notes', as: CRA, url: '/segmentation', scroll: '[data-column-picker]', prep: async (p) => { await p.locator('[data-note-indicator]:not([hidden])').first().locator('..').hover(); await p.waitForTimeout(300); }, marks: [['[data-note-indicator]:not([hidden])', 'left'], ['[data-note-popover]:not([hidden])', 'right']] },
    { name: '08-carry-over', as: SUPERVISOR, url: '/segmentation', scroll: '#backlog-title', hscroll: 'section[aria-labelledby=backlog-title] [data-transfer-open]', marks: [['#backlog-title', 'left'], ['section[aria-labelledby=backlog-title] tbody tr:first-child [data-note-cell] span.inline-block', 'right'], ['section[aria-labelledby=backlog-title] [data-transfer-open]', 'bottom'], ['section[aria-labelledby=backlog-title] [data-transfer-selected]', 'left']] },
    { name: '09-transfer-choose', as: SUPERVISOR, url: '/segmentation', scroll: '#backlog-title', prep: async (p) => { await p.locator('section[aria-labelledby=backlog-title] [data-transfer-open]').first().click(); }, marks: [['#transfer-dialog [data-transfer-targets]', 'left']] },
    { name: '10-transfer-confirm', as: SUPERVISOR, url: '/segmentation', scroll: '#backlog-title', prep: async (p) => { await p.locator('section[aria-labelledby=backlog-title] [data-transfer-open]').first().click(); await p.locator('[data-transfer-targets] button').first().click(); }, marks: [['#transfer-dialog [data-transfer-confirm-text]', 'left'], ['#transfer-dialog button[type=submit]', 'bottom']] },

    { name: '11-weekly', as: OWNER, url: '/segmentation/weekly', marks: [['[aria-label^="Week of"]', 'top'], ['main .grid.lg\\:grid-cols-4', 'left'], ['section[aria-labelledby=weekly-title] table', 'left']] },
    { name: '12-weekly-carry-over', as: SUPERVISOR, url: '/segmentation/weekly', scroll: '#unprocessed-title', marks: [['#unprocessed-title', 'top'], ['section[aria-labelledby=unprocessed-title] tbody tr:first-child td:nth-child(5) span', 'bottom'], ['section[aria-labelledby=unprocessed-title] [data-transfer-open]', 'left']] },

    { name: '13-user-access', as: OWNER, url: '/user-access', marks: [['form[action$="/user-access"]', 'bottom'], ['th:nth-child(2)', 'top'], ['tbody select[name=role_id]', 'bottom'], ['tbody td:last-child .flex', 'left']] },
    { name: '14-roles', as: OWNER, url: '/user-access/roles', marks: [['form[action$="/roles"] input[name=name]', 'top'], ['form[action$="/roles"] fieldset', 'left'], ['form[action$="/roles"] button[type=submit]', 'left']] },
    { name: '15-products', as: OWNER, url: '/settings/product-consumption', marks: [['form[action$="/product-consumption"]', 'bottom'], ['table input[name=keywords]', 'bottom']] },
];

const only = process.argv[2];
const browser = await chromium.launch({ channel: 'chrome' });

for (const shot of shots.filter((s) => !only || s.name.includes(only))) {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2 });
    const page = await context.newPage();
    try {
        await page.evaluate(() => { try { localStorage.clear(); } catch {} }).catch(() => {});
        if (shot.as) await page.goto(`${BASE}/_dev-login?email=${encodeURIComponent(shot.as)}`);
        await page.goto(BASE + shot.url);
        await page.waitForLoadState('networkidle');
        if (shot.scroll) {
            await page.locator(shot.scroll).first().evaluate((el) => window.scrollTo(0, el.getBoundingClientRect().top + window.scrollY - 120));
        }
        if (shot.hscroll) {
            // Bring a column that sits in a sideways-scrolling table into view.
            await page.locator(shot.hscroll).first().evaluate((el) => el.scrollIntoView({ block: 'nearest', inline: 'center' }));
        }
        if (shot.prep) await shot.prep(page);
        await page.waitForTimeout(250);
        const missing = await page.evaluate(annotate, shot.marks);
        if (missing.length) console.warn(`  ${shot.name}: not found → ${missing.join(' | ')}`);
        await page.screenshot({ path: path.join(OUT, `${shot.name}.png`) });
        console.log(`✓ ${shot.name}`);
    } catch (e) {
        console.error(`✗ ${shot.name}: ${e.message.split('\n')[0]}`);
    }
    await context.close();
}

await browser.close();

// Runs in the page: red box + numbered badge + arrow per target, drawn in viewport coordinates.
function annotate(marks) {
    const RED = '#E11D48';
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('style', 'position:fixed;inset:0;width:100vw;height:100vh;pointer-events:none;z-index:2147483647;overflow:visible');
    svg.innerHTML = `<defs><marker id="guide-arrow" markerWidth="10" markerHeight="10" refX="8" refY="5" orient="auto"><path d="M0,0 L10,5 L0,10 z" fill="${RED}"/></marker></defs>`;
    const missing = [];

    marks.forEach(([selector, side = 'left', nth = 0], i) => {
        const el = document.querySelectorAll(selector)[nth];
        if (!el) return missing.push(selector);
        const r = el.getBoundingClientRect();
        const pad = 4;
        const box = { x: r.left - pad, y: r.top - pad, w: r.width + pad * 2, h: r.height + pad * 2 };

        const rect = document.createElementNS(svg.namespaceURI, 'rect');
        Object.entries({ x: box.x, y: box.y, width: box.w, height: box.h, rx: 6, fill: 'none', stroke: RED, 'stroke-width': 3 }).forEach(([k, v]) => rect.setAttribute(k, v));
        svg.append(rect);

        const gap = 56;
        const cx = box.x + box.w / 2;
        const cy = box.y + box.h / 2;
        const badge = {
            left: [box.x - gap, cy, box.x - 2, cy],
            right: [box.x + box.w + gap, cy, box.x + box.w + 2, cy],
            top: [cx, box.y - gap, cx, box.y - 2],
            bottom: [cx, box.y + box.h + gap, cx, box.y + box.h + 2],
        }[side];
        // Keep badges on screen.
        badge[0] = Math.min(Math.max(badge[0], 18), window.innerWidth - 18);
        badge[1] = Math.min(Math.max(badge[1], 18), window.innerHeight - 18);

        const line = document.createElementNS(svg.namespaceURI, 'line');
        Object.entries({ x1: badge[0], y1: badge[1], x2: badge[2], y2: badge[3], stroke: RED, 'stroke-width': 3, 'marker-end': 'url(#guide-arrow)' }).forEach(([k, v]) => line.setAttribute(k, v));
        svg.append(line);

        const circle = document.createElementNS(svg.namespaceURI, 'circle');
        Object.entries({ cx: badge[0], cy: badge[1], r: 15, fill: RED, stroke: '#fff', 'stroke-width': 3 }).forEach(([k, v]) => circle.setAttribute(k, v));
        svg.append(circle);

        const text = document.createElementNS(svg.namespaceURI, 'text');
        Object.entries({ x: badge[0], y: badge[1] + 5, 'text-anchor': 'middle', fill: '#fff', 'font-size': 15, 'font-weight': 700, 'font-family': 'system-ui, sans-serif' }).forEach(([k, v]) => text.setAttribute(k, v));
        text.textContent = String(i + 1);
        svg.append(text);
    });

    document.body.append(svg);
    return missing;
}

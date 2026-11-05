// Vite plugin: writes build/assets/theme-dark.css, the dark theme, from the app's own compiled CSS (Tailwind utilities)
// and the colours written straight on the Blade views' SVG (chart grids, labels). Light surfaces become dark tints of
// the same hue on a purple-slate base, dark text becomes light, saturated fills (buttons, tiles) stay, yellows go
// slate. Loaded after app.css; applies under <html data-theme="dark"> (resources/js/theme.js).
import fs from 'node:fs';
import path from 'node:path';

const VIEWS = path.resolve('resources/views');

function bladeMarkup(dir = VIEWS) {
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const file = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            return bladeMarkup(file);
        }

        return entry.name.endsWith('.blade.php') ? [fs.readFileSync(file, 'utf8')] : [];
    }).join('\n');
}

const BASE = `/* CRD Deck dark theme, generated from the app's colour utilities (vite.dark-theme.js). */
html[data-theme="dark"]{color-scheme:dark}
html[data-theme="dark"] body{background:#211f2d;color:#f1eef8}
html[data-theme="dark"] input,html[data-theme="dark"] select,html[data-theme="dark"] textarea{color:#f1eef8}
html[data-theme="dark"] dialog{background:#2d2a3e;color:#f1eef8}
/* Row and list separators (divide-*). */
html[data-theme="dark"] [class*="divide-"] > :not(:last-child){border-color:#433e5a}
/* Raised cards: a soft dark shadow and a faint top light, like the light theme's white cards on the canvas. */
html[data-theme="dark"] .shadow-sm,html[data-theme="dark"] .shadow{box-shadow:0 1px 0 rgba(255,255,255,.04) inset,0 6px 18px rgba(8,4,20,.35)!important}
html[data-theme="dark"] .shadow-xl,html[data-theme="dark"] .shadow-2xl,html[data-theme="dark"] .shadow-lg{box-shadow:0 18px 40px rgba(8,4,20,.55)!important}
`;

/**
 * The dark theme's CSS for the compiled app CSS and the views' markup.
 */
export function darkThemeCss(css, markup) {
    const lines = [];
    // ---- colour math ----
    const clamp = (x, a = 0, b = 1) => Math.min(b, Math.max(a, x));
    function hexToRgb(h) {
      h = h.replace('#', '');
      if (h.length === 3 || h.length === 4) h = [...h].map((c) => c + c).join('');
      const n = parseInt(h.slice(0, 6), 16);
      return [(n >> 16) & 255, (n >> 8) & 255, n & 255].map((v) => v / 255);
    }
    function oklchToRgb(L, C, H) {
      const a = C * Math.cos((H * Math.PI) / 180), b = C * Math.sin((H * Math.PI) / 180);
      const l_ = L + 0.3963377774 * a + 0.2158037573 * b, m_ = L - 0.1055613458 * a - 0.0638541728 * b, s_ = L - 0.0894841775 * a - 1.291485548 * b;
      const l = l_ ** 3, m = m_ ** 3, s = s_ ** 3;
      const lin = [4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s, -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s, -0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s];
      return lin.map((c) => clamp(c <= 0.0031308 ? 12.92 * c : 1.055 * c ** (1 / 2.4) - 0.055));
    }
    function rgbToHsl([r, g, b]) {
      const max = Math.max(r, g, b), min = Math.min(r, g, b), l = (max + min) / 2;
      if (max === min) return [0, 0, l];
      const d = max - min, s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
      const h = max === r ? (g - b) / d + (g < b ? 6 : 0) : max === g ? (b - r) / d + 2 : (r - g) / d + 4;
      return [h * 60, s, l];
    }
    function hslToHex([h, s, l]) {
      const f = (n) => { const k = (n + h / 30) % 12, a = s * Math.min(l, 1 - l); return l - a * Math.max(-1, Math.min(k - 3, 9 - k, 1)); };
      return '#' + [f(0), f(8), f(4)].map((v) => Math.round(clamp(v) * 255).toString(16).padStart(2, '0')).join('');
    }
    const lum = ([r, g, b]) => { const c = (v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4); return 0.2126 * c(r) + 0.7152 * c(g) + 0.0722 * c(b); };

    // --color-* variables from the CSS.
      const vars = {};
      for (const m of css.matchAll(/--color-([a-z0-9-]+):([^;}]+)/g)) vars[m[1]] = m[2].trim();
    function parseColor(v) {
      v = v.trim();
      let alpha = 1;
      const mix = v.match(/^color-mix\(in (?:oklab|srgb),\s*(.+?)\s+(\d+(?:\.\d+)?)%,\s*transparent\)$/);
      if (mix) { alpha = Number(mix[2]) / 100; v = mix[1]; }
      const ref = v.match(/^var\(--color-([a-z0-9-]+)\)$/);
      if (ref) { const r = parseColor(vars[ref[1]] ?? ''); return r && { ...r, alpha: alpha * r.alpha, name: ref[1] }; }
      if (/^#[0-9a-f]{3,8}$/i.test(v)) return { rgb: hexToRgb(v), alpha };
      // oklab(L a b / alpha), as Tailwind writes an arbitrary colour with an opacity (border-[#e0b400]/60).
      const lab = v.match(/^oklab\((-?[\d.]+)(%?)\s+(-?[\d.]+)\s+(-?[\d.]+)(?:\s*\/\s*([\d.]+)(%?))?\)/);
      if (lab) {
        const L = Number(lab[1]) / (lab[2] ? 100 : 1), A = Number(lab[3]), B = Number(lab[4]);
        const C = Math.hypot(A, B), H = (Math.atan2(B, A) * 180) / Math.PI;
        const a2 = lab[5] === undefined ? 1 : Number(lab[5]) / (lab[6] ? 100 : 1);
        return { rgb: oklchToRgb(L, C, H), alpha: alpha * a2 };
      }
      const ok = v.match(/^oklch\(([\d.]+)%?\s+([\d.]+)\s+([\d.]+)/);
      if (ok) return { rgb: oklchToRgb(Number(ok[1]) / (v.includes('%') ? 100 : 1), Number(ok[2]), Number(ok[3])), alpha };
      if (v === 'white' || v === '#fff') return { rgb: [1, 1, 1], alpha };
      return null;
    }

    // Named surfaces of the deck, picked by hand.
    const SURFACE = { white: '#2d2a3e', canvas: '#211f2d', 'brand-50': '#3a3157', 'brand-100': '#463a6c', 'brand-200': '#5a4a88', line: '#433e5a' };
    const TEXT = { ink: '#f1eef8', muted: '#bdb6cf', 'brand-700': '#c39bff', 'brand-600': '#b98aff', 'teal-700': '#3fd1b9', 'coral-700': '#ff9a9d' };
    const withAlpha = (hex, a) => (a >= 0.999 ? hex : `color-mix(in oklab, ${hex} ${Math.round(a * 100)}%, transparent)`);

    // Yellows / ambers (note boxes, input boxes): olive in the dark, so they become a raised neutral slate instead.
    const isYellow = ([h, s]) => h >= 35 && h <= 65 && s > 0.3;
    function darkSurface(c) {
      if (c.name && SURFACE[c.name]) return withAlpha(SURFACE[c.name], c.alpha);
      if (lum(c.rgb) < 0.55) return null; // saturated or dark fill: keep
      if (isYellow(rgbToHsl(c.rgb))) return withAlpha('#3a3652', c.alpha);
      const [h, s] = rgbToHsl(c.rgb);
      // Near-greys stay purple-slate like the panels; real tints keep their colour, a step lighter than the panel.
      return withAlpha(s < 0.12 ? '#34304a' : hslToHex([h, Math.min(s, 0.6) * 0.75, 0.27]), c.alpha);
    }
    function darkBorder(c) {
      if (c.name && (c.name === 'line' || c.name === 'white')) return withAlpha('#433e5a', c.alpha);
      if (c.name && SURFACE[c.name]) return withAlpha(SURFACE[c.name], c.alpha);
      // Yellow outlines (note boxes, input boxes) go slate too, however strong.
      if (isYellow(rgbToHsl(c.rgb))) return withAlpha('#57516f', c.alpha);
      if (lum(c.rgb) < 0.5) return null;
      const [h, s] = rgbToHsl(c.rgb);
      return withAlpha(s < 0.12 ? '#433e5a' : hslToHex([h, Math.min(s, 0.6) * 0.7, 0.38]), c.alpha);
    }
    function darkText(c) {
      if (c.name && TEXT[c.name]) return withAlpha(TEXT[c.name], c.alpha);
      if (lum(c.rgb) > 0.3) return null; // already light
      if (isYellow(rgbToHsl(c.rgb))) return withAlpha('#efe8d2', c.alpha);
      const [h, s, l] = rgbToHsl(c.rgb);
      return withAlpha(hslToHex([h, s < 0.12 ? s : Math.min(s, 0.75), clamp(0.62 + (0.5 - Math.min(l, 0.5)) * 0.55, 0.62, 0.9)]), c.alpha);
    }

    // ---- walk the utilities ----
    const PROPS = {
      'background-color': darkSurface, color: darkText, 'border-color': darkBorder, 'border-top-color': darkBorder, 'border-bottom-color': darkBorder,
      'border-left-color': darkBorder, 'border-right-color': darkBorder, 'outline-color': darkBorder, '--tw-ring-color': darkBorder,
      '--tw-gradient-from': darkSurface, '--tw-gradient-to': darkSurface, '--tw-gradient-via': darkSurface, fill: darkText, stroke: darkBorder,
    };
    const PSEUDO = { checked: ':checked', disabled: ':disabled', hover: ':hover', focus: ':focus', 'focus-visible': ':focus-visible', 'focus-within': ':focus-within', placeholder: '::placeholder', open: '[open]' };
    const re = /(\.[^{}@,]+?)\{([^{}]*(?:\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}[^{}]*)*)\}/g;
    for (const m of css.matchAll(re)) {
      // The class itself (escaped), without the compiled :is()/:where() or pseudo part after it.
      const base = m[1].trim().match(/^\.(?:\\.|[^:\\\s])+/);
      if (!base) continue;
      const sel = base[0];
      const body = m[2];
      const raw = sel.slice(1).replace(/\\(.)/g, '$1');
      const parts = raw.split(/:(?![^\[]*\])/);
      const variants = parts.slice(0, -1);
      if (variants.some((v) => !(v in PSEUDO) && !['group-hover', 'group-open', 'peer-checked', 'aria-pressed', 'aria-selected', 'lg', 'md', 'sm', 'xl'].includes(v))) continue;
      const selector = sel;
      let pre = 'html[data-theme="dark"] ';
      let post = '';
      for (const v of variants) {
        if (PSEUDO[v]) post += PSEUDO[v];
        if (v === 'group-hover') pre += '.group:hover ';
        if (v === 'group-open') pre += '.group[open] ';
        if (v === 'peer-checked') pre += '.peer:checked ~ ';
        if (v === 'aria-pressed') post += '[aria-pressed="true"]';
        if (v === 'aria-selected') post += '[aria-selected="true"]';
      }
      const decls = [];
      for (const d of body.matchAll(/([a-z-]+|--tw-[a-z-]+)\s*:\s*([^;{}]+)/g)) {
        const fn = PROPS[d[1]];
        if (!fn) continue;
        const c = parseColor(d[2].replace(/\s*!important/, ''));
        if (!c) continue;
        const out = fn(c);
        if (out) decls.push(`${d[1]}:${out}`);
      }
      if (decls.length) lines.push(`${pre}${selector}${post}{${decls.join(';')}}`);
    }


    // Colours written straight on the views' SVG and markup (chart grids, labels).
    const attrColors = new Set();
    for (const m of markup.matchAll(/(fill|stroke)="(#[0-9a-fA-F]{3,6})"/g)) {
        attrColors.add(m[1] + '|' + m[2]);
    }
    for (const key of attrColors) {
        const [prop, hex] = key.split('|');
        const c = { rgb: hexToRgb(hex), alpha: 1 };
        // Near-grey light strokes/fills (chart grids, donut tracks) are lines: the dark theme's line colour.
        const out = lum(c.rgb) > 0.6 ? (rgbToHsl(c.rgb)[1] < 0.35 ? '#4a4562' : darkBorder(c)) : darkText(c);
        if (out) {
            lines.push(`html[data-theme="dark"] [${prop}="${hex}"]{${prop}:${out}}`);
        }
    }

    return BASE + lines.join('\n') + '\n';
}

/**
 * Emits assets/theme-dark.css next to the built app.css.
 */
export default function darkTheme() {
    return {
        name: 'crd-dark-theme',
        apply: 'build',
        generateBundle(options, bundle) {
            const appCss = Object.values(bundle).find((file) => file.type === 'asset' && /assets\/app-[^/]+\.css$/.test(file.fileName));
            if (!appCss) {
                return;
            }

            this.emitFile({ type: 'asset', fileName: 'assets/theme-dark.css', source: darkThemeCss(String(appCss.source), bladeMarkup()) });
        },
    };
}

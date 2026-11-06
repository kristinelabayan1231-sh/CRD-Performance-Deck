/* Sample data + renderers for the Exclusive Lead Tracker mockups (v2). Static, no backend, made-up names. */
const fmt = (n) => Number(n).toLocaleString('en-US');
const pct = (a, b) => Math.round((a / b) * 100);

const GOAL = { leadsDay: 250, leadsMonth: 7000, repeatPct: 20, repeatMonth: 1400, repeatDay: 50, orders: 54, aov: 895 };
const PRI = {
  vip: { label: 'High AOV / VIP', cls: 'd-vip', bg: '#ecdeff', fg: '#5a2aa8' },
  twice: { label: 'Handled twice', cls: 'd-twice', bg: '#d6f3ee', fg: '#0b6b5c' },
  rand: { label: 'Random', cls: 'd-rand', bg: '#dbe8fb', fg: '#1d4f9f' },
};
/* mix = [vip, twice, random]. Nov 6 = 88 left over from Nov 5 + 162 fresh. */
const DAYS = {
  1: { leads: 100, repeat: 9, orders: 2, aov: 861, mix: [20, 45, 35], hist: true },
  2: { leads: 100, repeat: 11, orders: 3, aov: 884, mix: [18, 47, 35], hist: true },
  3: { leads: 100, repeat: 8, orders: 1, aov: 902, mix: [22, 41, 37], hist: true },
  4: { leads: 100, repeat: 12, orders: 3, aov: 879, mix: [19, 44, 37], hist: true },
  5: { leads: 250, worked: 162, left: 88, repeat: 28, orders: 4, aov: 889, mix: [48, 100, 102], today: true },
  6: { leads: 250, worked: 0, left: 250, repeat: 0, orders: 0, aov: null, mix: [50, 105, 95], carry: [12, 31, 45], fresh: [38, 74, 50], next: true },
};
const MONTH = { leads: 650, repeat: 68, orders: 13, aov: 880, daysDone: 4 };
const dName = (d) => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][(d + 0) % 7]; // Nov 1, 2026 is a Sunday
const dLong = (d) => `${dName(d - 1)}, Nov ${d}`;

const ICON = {
  chart: '<path d="M3 3h2v16h16v2H3V3Zm4 10h3v4H7v-4Zm5-5h3v9h-3V8Zm5-3h3v12h-3V5Z"/>',
  home: '<path d="M12 3 2 12h3v8h5v-6h4v6h5v-8h3z"/>',
  star: '<path d="m12 2 2.9 6.3 6.9.8-5.1 4.7 1.4 6.8L12 17.2 5.9 20.6l1.4-6.8L2.2 9.1l6.9-.8z"/>',
  trend: '<path d="M3 17.6 9.2 11.4l4 4L19.6 9H17V7h6v6h-2v-2.6l-7.8 7.8-4-4L4.4 19z"/>',
  users: '<path d="M16 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm-8 0a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm0 2c-2.7 0-8 1.3-8 4v2h16v-2c0-2.7-5.3-4-8-4Zm8 0c-.3 0-.7 0-1.1.1 1.3.9 2.1 2.2 2.1 3.9v2h7v-2c0-2.7-5.3-4-8-4Z"/>',
  gear: '<path d="M19.4 13a7.5 7.5 0 0 0 0-2l2.1-1.6-2-3.5-2.5 1a7.6 7.6 0 0 0-1.7-1L15 3.3h-4l-.4 2.6a7.6 7.6 0 0 0-1.7 1l-2.5-1-2 3.5L6.5 11a7.5 7.5 0 0 0 0 2l-2.1 1.6 2 3.5 2.5-1c.5.4 1.1.7 1.7 1l.4 2.6h4l.4-2.6c.6-.3 1.2-.6 1.7-1l2.5 1 2-3.5L19.4 13ZM13 15.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7Z"/>',
};
const svg = (k, s = 'width:20px;height:20px') => `<svg style="${s}" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">${ICON[k]}</svg>`;

function sidebar(settingsCur = false) {
  const a = (ic, label, cur, extra = '', cls = '') => `<a class="${cls}" href="#" ${cur ? 'aria-current="page"' : ''} onclick="return false"><span>${label}</span>${extra || svg(ic)}</a>`;
  return `<div class="logo"><i></i><span>CRD Deck</span></div>
  <div class="me"><div class="av">SL</div><div><p class="b">Sample Supervisor</p><p class="xs muted">Supervisor</p></div></div>
  <nav aria-label="Main">${a('home', 'Dashboard')}${a('chart', 'Segmentation Tracker')}
  ${a('star', 'Exclusive Lead Tracker', true, '<span class="new">NEW</span>')}${a('trend', 'Segmentation Productivity')}${a('users', 'User Access')}${a('gear', 'Settings')}</nav>`;
}
function topbar() {
  return `<span class="mock-flag">UI proposal · static mockup · sample data</span>
  <div class="row" style="gap:12px"><button class="btn theme-btn" type="button" id="theme" aria-label="Switch light or dark theme" title="Light / dark">◐</button>
  <div class="u"><div class="av sm">SL</div><span>Sample Supervisor</span></div></div>`;
}
function pageHead(sub, actions = '') {
  return `<div class="head"><div class="t"><span class="ic">${svg('star')}</span><div><h1>Exclusive Lead Tracker</h1><p class="sub">${sub}</p></div></div>
  <div class="row sm muted"><span class="row" style="gap:6px"><i class="dot" style="background:var(--teal);border-radius:50%"></i>Auto-synced 12 minutes ago</span>${actions}</div></div>`;
}

/* ---- deterministic sample leads, same 17 columns as the Segmentation Tracker ---- */
const NAMES = ['Maria Santos', 'Ana Reyes', 'Liza Dela Cruz', 'Grace Bautista', 'Jenny Ramos', 'Carla Mendoza', 'Rose Villanueva', 'Tina Garcia', 'Nena Aquino', 'Cecile Navarro', 'Ruth Fernandez', 'Mylene Castillo', 'Lorna Domingo', 'Joy Pascual', 'Elena Soriano', 'Bea Marquez', 'Hazel Ocampo', 'Irene Salazar', 'Wilma Cortez', 'Dina Robles', 'Fe Magsaysay', 'Pia Alcantara', 'Sheila Lim', 'Rina Del Rosario', 'Cora Villegas', 'Nora Estrada', 'Gina Padilla', 'Ella Concepcion'];
const PRODS = ['Collagen Plus 30s', 'Gluta Max 60s', 'Slim Tea 20 sachets', 'Vita C 1000 100s', 'Probiotic Daily 30s', 'Hair Revive Set'];
const STATUS = {
  active: ['Active', '#d4edbc', '#11734b'], inactive: ['Inactive', '#e6e6e6', '#3d3d3d'], pjr: ['PJR/Inactive/CBR/Drop call', '#e6cff2', '#5a3286'],
  busy: ['Busy/Callback', '#753800', '#ffcfc9'], ffup: ['Reminders/FFUP', '#c6dbe1', '#0a53a8'], rp: ['Repeat Purchase', '#ffcfc9', '#b10202'], blocked: ['Blocked', '#3d3d3d', '#fff'],
};
const YESNO = { yes: ['Yes', '#d4edbc', '#11734b'], no: ['No', '#e6e6e6', '#3d3d3d'], reserve: ['Reserve', '#ffe5a0', '#473821'] };
const TAGS = [['Hot Leads / Recent Buyers (0 to 15 days)', '#ef6a3a', '#3b0d00'], ['Warm Leads / Old Customers (16 to 30 days)', '#ffe5a0', '#473821'], ['High Value Customers', '#d4edbc', '#11734b'], ['Repeat Customers', '#ffcfc9', '#b10202']];
const FEED = [['PURCHASED', '#d4edbc', '#11734b'], ['NO BUDGET', '#ffe5a0', '#473821'], ['STILL HAVE STOCKS', '#c6dbe1', '#0a53a8'], ['CURRENTLY USING', '#bfe1f6', '#0a53a8']];
const TIMES = ['9:00AM-10:00AM', '11:00AM-12:00NN', '2:00PM-3:00PM', '4:00PM-5:00PM'];
const sel = (v) => `<span class="sel" style="background:${v[1]};color:${v[2]}">${v[0]}</span>`;
const dash = '<span class="muted">—</span>';
const mj = (d) => `Nov ${d}, 2026`;
const oj = (d) => new Date(2026, 10, d).toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" });

function leadRows(day, count = 25) {
  const info = DAYS[day], worked = info.hist || info.today;
  const pris = ['vip', 'twice', 'twice', 'rand', 'vip', 'rand', 'twice', 'rand'];
  return Array.from({ length: count }, (_, i) => {
    const k = i + day * 3;
    const carried = info.next && i % 3 === 0;
    const oos = carried ? 5 : day;
    const cons = 30 + ((k * 7) % 31);
    const delivered = oos - cons;
    const st = (worked || carried) ? ['active', 'busy', 'ffup', 'rp', 'pjr', '', 'active', 'inactive'][k % 8] : '';
    const done = st && st !== 'rp' ? true : false;
    return {
      name: NAMES[k % NAMES.length], qty: (k % 3) + 1, prod: PRODS[k % PRODS.length], tel: `0917 ${String(100 + ((k * 53) % 900))} ${String(1000 + ((k * 379) % 9000))}`,
      delivered: oj(delivered), days: day - delivered, oos: oj(oos), rec: oj(oos - 7), type: k % 4 === 3 ? 'New Customer' : 'CRD Lead',
      pri: pris[k % pris.length], carried, st,
      rp: st === 'rp' ? 'yes' : (st === 'active' ? 'no' : (st === 'ffup' ? 'reserve' : '')),
      tag: st ? TAGS[k % 4] : null, cdate: st ? mj(Math.max(1, Math.min(day, 5))) : '', ctime: st ? TIMES[k % 4] : '',
      fb: st && done ? FEED[k % 4] : null, cb: st === 'busy' || st === 'ffup' ? mj(day + 1 + (k % 3)) : '',
    };
  });
}
const COLS = ['Customer Name', 'Qty', 'Product', 'Contact #', 'Delivered Date', 'Days since Delivered', 'Est. Out of Stock', 'Recommended Replenishment Day', 'Type', 'Assigned to', 'Status', 'Repeat Purchase?', 'Customer Tagging', 'Date of Contact', 'Time of Contact', "Customer's Feedback", 'Callback Date'];

function leadTable({ day = 6, rows = 25, total, title = '', foot = true, maxH = 600, bar = '', pri = '' } = {}) {
  const info = DAYS[day]; total = total || info.leads;
  const body = (pri ? leadRows(day, rows * 4).filter((x) => x.pri === pri).slice(0, rows) : leadRows(day, rows)).map((x) => {
    const p = PRI[x.pri];
    return `<tr><td class="stick">${x.name}
        <div class="src"><i class="dot ${p.cls}"></i>${p.label}${x.carried ? ' · <span style="color:var(--carry)">left over from Nov 5</span>' : ''}</div></td>
      <td class="r num">${x.qty}</td><td>${x.prod}</td><td class="num">${x.tel}</td><td>${x.delivered}</td><td class="yel num">${x.days}</td><td>${x.oos}</td><td>${x.rec}</td><td>${x.type}</td>
      <td><span class="pill cra">Joanna Paclibare</span></td>
      <td>${x.st ? sel(STATUS[x.st]) : '<span class="muted">— Set status —</span>'}</td>
      <td>${x.rp ? sel(YESNO[x.rp]) : dash}</td><td>${x.tag ? sel(x.tag) : dash}</td><td>${x.cdate || dash}</td><td>${x.ctime || dash}</td><td>${x.fb ? sel(x.fb) : dash}</td><td>${x.cb || dash}</td></tr>`;
  }).join('');
  const opt = COLS.slice(11);
  return `<section class="card" style="overflow:hidden" aria-label="Leads for ${dLong(day)}">
    <div class="row between pad" style="border-bottom:1px solid var(--line)">
      <div class="sm"><b>${title || `${dLong(day)} · <span class="num">${fmt(total)}</span> leads`}</b>${info.hist ? ' <span class="chip" style="background:var(--canvas);color:var(--muted)">History · 100-lead day</span>' : ''}${info.next ? ' <span class="chip" style="background:#fde3da;color:#a2391b">Opens tomorrow</span>' : ''}</div>
      <div class="row">${bar}<details class="cols"><summary class="btn">Columns</summary><div class="pop"><p class="xs muted" style="padding-bottom:6px">Customer Name to Status are always shown.</p>
        ${opt.map((o) => `<label><input type="checkbox" checked style="accent-color:var(--brand-500)"> ${o}</label>`).join('')}</div></details></div></div>
    <div class="tw" style="max-height:${maxH}px"><table><thead><tr>
      <th class="stick" style="background:var(--th)">${COLS[0]}</th><th class="r">${COLS[1]}</th>${COLS.slice(2, 5).map((c) => `<th>${c}</th>`).join('')}<th class="yel">${COLS[5]}</th>${COLS.slice(6, 11).map((c) => `<th>${c}</th>`).join('')}
      ${opt.map((c) => `<th class="grn">${c}</th>`).join('')}</tr></thead><tbody>${body}</tbody></table></div>
    ${foot ? `<div class="tbl-foot"><span>Showing 1–${rows} of ${fmt(total)} leads</span><div class="pg"><span class="on">1</span><span>2</span><span>3</span><span>…</span><span>${Math.ceil(total / rows)}</span></div></div>` : ''}
  </section>`;
}
/* Same filter row as the Segmentation Tracker (Month, Date, CRA, Type, Status), CRA locked to Joanna. */
function filters(day = 6) {
  return `<form class="card filters mb" onsubmit="return false">
    <label><span>Month</span><input class="ctl" type="month" value="2026-11"></label>
    <label><span>Date</span><input class="ctl" type="date" value="2026-11-${String(day).padStart(2, '0')}"></label>
    <label><span>CRA</span><select class="ctl locked" disabled><option>Joanna Paclibare</option></select></label>
    <label><span>Type</span><select class="ctl"><option>All types</option><option>CRD Lead</option><option>New Customer</option></select></label>
    <label><span>Status</span><select class="ctl"><option>All statuses</option><option>No status yet</option>${Object.values(STATUS).map((s) => `<option>${s[0]}</option>`).join('')}</select></label>
    <a class="btn" style="height:40px" href="#" onclick="return false">Today</a></form>`;
}

/* ---- metric helpers ---- */
function meter(label, a, b, color, { tail = '', pace = null, unit = '' } = {}) {
  return `<div><div class="row between sm" style="margin-bottom:4px;gap:8px"><span class="b">${label}</span><span class="num"><b>${fmt(a)}${unit}</b> <span class="muted">/ ${fmt(b)}${unit} · ${pct(a, b)}%</span></span></div>
  <div class="bar" role="progressbar" aria-label="${label}" aria-valuenow="${a}" aria-valuemin="0" aria-valuemax="${b}"><i style="width:${Math.min(100, (a / b) * 100)}%;background:${color}"></i>${pace != null ? `<span class="mark" style="left:${pace * 100}%" title="Where she should be by now"></span>` : ''}</div>
  ${tail ? `<p class="xs muted" style="margin-top:4px">${tail}</p>` : ''}</div>`;
}
function mixBar(m) {
  const k = ['vip', 'twice', 'rand'];
  return `<div class="mix" role="img" aria-label="Mix: ${m[0]} VIP, ${m[1]} handled twice, ${m[2]} random">${m.map((v, i) => `<span class="${PRI[k[i]].cls}" style="flex:${v}" title="${PRI[k[i]].label}: ${v}">${v / m.reduce((a, b) => a + b) > .1 ? v : ''}</span>`).join('')}</div>`;
}
function mixLegend(m) {
  return `<div class="legend" style="margin-top:8px">${['vip', 'twice', 'rand'].map((k, i) => `<span><i class="dot ${PRI[k].cls}"></i>${i + 1}. ${PRI[k].label} <b class="num">${m[i]}</b></span>`).join('')}</div>`;
}
function donut(a, b, color, label, sub) {
  const r = 38, c = 2 * Math.PI * r, p = Math.min(1, a / b);
  return `<div style="display:grid;justify-items:center;gap:6px;text-align:center"><svg width="108" height="108" viewBox="0 0 100 100" role="img" aria-label="${label}: ${fmt(a)} of ${fmt(b)}">
    <circle cx="50" cy="50" r="${r}" fill="none" stroke="var(--canvas)" stroke-width="11"/><circle cx="50" cy="50" r="${r}" fill="none" stroke="${color}" stroke-width="11" stroke-linecap="round" stroke-dasharray="${c * p} ${c}" transform="rotate(-90 50 50)"/>
    <text x="50" y="48" text-anchor="middle" font-size="17" font-weight="700" fill="var(--ink)">${fmt(a)}</text><text x="50" y="63" text-anchor="middle" font-size="9" fill="var(--muted)">of ${fmt(b)}</text></svg>
    <div class="sm b">${label}</div><div class="xs muted">${sub}</div></div>`;
}

/* ---- Settings (Transfer a CRA into the Exclusive Lead Tracker) ---- */
function settingsBody() {
  return `<div class="note warn">Only supervisors and leads can change this. A CRA moved here <b>leaves the Segmentation Tracker</b> and works only from the Exclusive Lead Tracker. Joanna Paclibare is no longer listed in the Segmentation Tracker.</div>
  <div><h3 class="b" style="margin-bottom:8px">CRAs in the Exclusive Lead Tracker <span class="chip" style="background:var(--soft);color:var(--soft-ink)">1</span></h3>
    <div class="card" style="box-shadow:none;border:1px solid var(--line);overflow:auto"><table>
      <thead><tr><th>CRA</th><th class="r">Leads / day</th><th class="r">Leads / month</th><th>Since</th><th></th></tr></thead>
      <tbody><tr><td><span class="row nw" style="gap:10px"><span class="av sm">JP</span>Joanna Paclibare</span></td><td class="r num">250</td><td class="r num">7,000</td><td>Nov 1, 2026</td><td class="r"><button class="btn" type="button" style="height:30px">Return to Segmentation</button></td></tr></tbody></table></div>
    <p class="xs muted" style="margin-top:6px">Leads assigned to her this month stay with her, even if she is returned mid-month.</p></div>
  <div style="border-top:1px solid var(--line);padding-top:16px"><h3 class="b" style="margin-bottom:10px">Transfer a CRA into the Exclusive Lead Tracker</h3>
    <div style="display:grid;gap:12px">
      <label class="field"><span>CRA (from the Segmentation Tracker)</span><select class="ctl"><option>Select a CRA…</option><option>Ana Lopez</option><option>Mark Tan</option><option>Carlo Reyes</option></select></label>
      <div class="two">
        <label class="field"><span>Leads per day</span><input class="ctl" value="250" inputmode="numeric"></label>
        <label class="field"><span>Leads per month</span><input class="ctl locked" value="7,000" readonly></label>
        <label class="field"><span>Repeat purchase goal (%)</span><input class="ctl" value="20"></label>
        <label class="field"><span>Confirmed orders target / month (to confirm)</span><input class="ctl" value="54"></label>
        <label class="field"><span>High AOV threshold (₱)</span><input class="ctl" value="895"></label>
        <label class="field"><span>Effective date</span><input class="ctl" type="date" value="2026-11-06"></label></div>
      <div><p class="xs b muted" style="margin-bottom:6px">Daily mix, in this priority</p><ol class="order"><li>High AOV / VIP leads (AOV ₱895 and above)</li><li>Leads handled twice</li><li>Random leads</li></ol></div></div></div>`;
}
const settingsFoot = (close = '') => `<button class="btn" type="button" ${close}>Cancel</button><button class="btn primary" type="button" disabled title="Pick a CRA first">Transfer to Exclusive</button>`;

/* ---- mount ---- */
function mount() {
  const q = (s, f) => document.querySelectorAll(s).forEach(f);
  q('[data-sidebar]', (e) => (e.innerHTML = sidebar()));
  q('[data-topbar]', (e) => (e.innerHTML = topbar()));
  q('[data-settings-body]', (e) => (e.innerHTML = settingsBody()));
  q('[data-settings-foot]', (e) => (e.innerHTML = settingsFoot(e.dataset.close || '')));
  q('[data-open]', (b) => b.addEventListener('click', () => document.getElementById(b.dataset.open).showModal()));
  q('[data-close-dialog]', (b) => b.addEventListener('click', () => b.closest('dialog').close()));
  q('dialog', (d) => d.addEventListener('click', (e) => { if (e.target === d) d.close(); }));
  const root = document.documentElement, saved = localStorage.getItem('elt-theme');
  if (saved || location.hash.includes('dark')) root.dataset.theme = saved || 'dark';
  document.getElementById('theme')?.addEventListener('click', () => { root.dataset.theme = root.dataset.theme === 'dark' ? 'light' : 'dark'; try { localStorage.setItem('elt-theme', root.dataset.theme); } catch (e) {} });
  if (typeof page === 'function') page();
}
document.addEventListener('DOMContentLoaded', mount);

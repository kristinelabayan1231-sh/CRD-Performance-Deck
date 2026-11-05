/* Shared sample data + renderers for the Exclusive Lead Tracker mockups (static, no backend). */
const fmt = (n) => Number(n).toLocaleString('en-US');
const pct = (a, b) => Math.round((a / b) * 100);

const D = {
  today: 'Thu, Nov 5',
  tomorrow: 'Fri, Nov 6',
  month: { leads: [650, 7000], repeat: [68, 1400], orders: [13, 54], aov: [880, 895], elapsed: 5 / 30 },
  day: { assigned: 250, worked: 162, left: 88, repeat: [28, 50], orders: [4, 2], aov: [889, 895] },
  history: [
    { d: 'Sun, Nov 1', leads: 100, repeat: 9, orders: 2, aov: 861 },
    { d: 'Mon, Nov 2', leads: 100, repeat: 11, orders: 3, aov: 884 },
    { d: 'Tue, Nov 3', leads: 100, repeat: 8, orders: 1, aov: 902 },
    { d: 'Wed, Nov 4', leads: 100, repeat: 12, orders: 3, aov: 879 },
  ],
  // Nov 5 actual days for timeline
  days: [
    { d: 1, leads: 100, repeat: 9, orders: 2 }, { d: 2, leads: 100, repeat: 11, orders: 3 },
    { d: 3, leads: 100, repeat: 8, orders: 1 }, { d: 4, leads: 100, repeat: 12, orders: 3 },
    { d: 5, leads: 250, repeat: 28, orders: 4, worked: 162 },
  ],
  carry: { from: 'Nov 5', count: 88, vip: 12, twice: 31, rand: 45 },
  tomorrowMix: { vip: 50, twice: 105, rand: 95 }, // 88 carried + 162 fresh = 250
  fresh: { vip: 38, twice: 74, rand: 50 },
};

const ICON = {
  chart: '<path d="M3 3h2v16h16v2H3V3Zm4 10h3v4H7v-4Zm5-5h3v9h-3V8Zm5-3h3v12h-3V5Z"/>',
  home: '<path d="M12 3 2 12h3v8h5v-6h4v6h5v-8h3z"/>',
  star: '<path d="m12 2 2.9 6.3 6.9.8-5.1 4.7 1.4 6.8L12 17.2 5.9 20.6l1.4-6.8L2.2 9.1l6.9-.8z"/>',
  trend: '<path d="M3 17.6 9.2 11.4l4 4L19.6 9H17V7h6v6h-2v-2.6l-7.8 7.8-4-4L4.4 19z"/>',
  users: '<path d="M16 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm-8 0a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm0 2c-2.7 0-8 1.3-8 4v2h16v-2c0-2.7-5.3-4-8-4Zm8 0c-.3 0-.7 0-1.1.1 1.3.9 2.1 2.2 2.1 3.9v2h7v-2c0-2.7-5.3-4-8-4Z"/>',
  gear: '<path d="M19.4 13a7.5 7.5 0 0 0 0-2l2.1-1.6-2-3.5-2.5 1a7.6 7.6 0 0 0-1.7-1L15 3.3h-4l-.4 2.6a7.6 7.6 0 0 0-1.7 1l-2.5-1-2 3.5L6.5 11a7.5 7.5 0 0 0 0 2l-2.1 1.6 2 3.5 2.5-1c.5.4 1.1.7 1.7 1l.4 2.6h4l.4-2.6c.6-.3 1.2-.6 1.7-1l2.5 1 2-3.5L19.4 13ZM13 15.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7Z"/>',
};
const svg = (k, c = 'width:20px;height:20px') => `<svg style="${c}" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">${ICON[k]}</svg>`;

function sidebar() {
  const link = (ic, label, cur, extra = '') =>
    `<a href="#" ${cur ? 'aria-current="page"' : ''} onclick="return false"><span>${label} ${extra}</span>${svg(ic)}</a>`;
  return `<div class="logo"><i></i><span>CRD Deck</span></div>
  <div class="me"><div class="av">SL</div><div><p class="b">Sample Supervisor</p><p class="xs muted">Supervisor</p></div></div>
  <nav aria-label="Main">
    ${link('home', 'Dashboard')}
    ${link('chart', 'Segmentation Tracker')}
    ${link('star', 'Exclusive Lead Tracker', true, '<span class="new" title="New module" style="width:8px;height:8px;padding:0;display:inline-block"></span>')}
    ${link('trend', 'Segmentation Productivity')}
    ${link('users', 'User Access')}
    ${link('gear', 'Settings')}
  </nav>`;
}

function topbar(label) {
  return `<span class="mock-flag">UI proposal · static mockup, sample data</span>
  <div class="u"><div class="av sm">SL</div><span>Sample Supervisor</span></div>`;
}

function pageHead(sub, actions = '') {
  return `<div class="head"><div class="t"><span class="ic">${svg('star')}</span>
    <div><h1>Exclusive Lead Tracker</h1><p class="sub">${sub}</p></div></div>
    <div class="row sm muted"><span class="row" style="gap:6px"><span aria-hidden="true" class="dot" style="background:var(--teal);border-radius:50%"></span>Auto-synced 12 minutes ago</span>${actions}</div></div>`;
}

function filters(withCra = true) {
  return `<form class="card filters mb" onsubmit="return false">
    <label><span>Month</span><input class="ctl" type="month" value="2026-11"></label>
    <label><span>Date</span><input class="ctl" type="date" value="2026-11-05"></label>
    <label><span>CRA</span><select class="ctl locked" disabled aria-describedby="cra-note"><option>Joanna Paclibare</option></select></label>
    <label><span>Type</span><select class="ctl"><option>All types</option><option>CRD Lead</option><option>New Customer</option></select></label>
    <label><span>Priority</span><select class="ctl"><option>All priorities</option><option>High AOV / VIP</option><option>Handled twice</option><option>Random</option></select></label>
    <label><span>Origin</span><select class="ctl"><option>All</option><option>Carried over</option><option>Fresh</option></select></label>
    <label><span>Status</span><select class="ctl"><option>All statuses</option><option>No status yet</option><option>Active</option><option>Repeat Purchase</option><option>Busy/Callback</option></select></label>
    <a class="btn" style="height:40px" href="#" onclick="return false">Today</a>
  </form>`;
}

/* ---- Lead table ---- */
const PRI = {
  vip: ['High AOV / VIP', '#ecdeff', '#5a2aa8', 'd-vip'],
  twice: ['Handled twice', '#d6f3ee', '#0b6b5c', 'd-twice'],
  rand: ['Random', '#dbe8fb', '#1d4f9f', 'd-rand'],
};
const STATUS = {
  '': ['—', 'transparent', 'var(--muted)'],
  active: ['Active', '#d4edbc', '#11734b'],
  rp: ['Repeat Purchase', '#ffcfc9', '#b10202'],
  busy: ['Busy/Callback', '#753800', '#ffcfc9'],
  ffup: ['Reminders/FFUP', '#c6dbe1', '#0a53a8'],
  inactive: ['Inactive', '#e6e6e6', '#3d3d3d'],
};
const NAMES = ['Maria Santos', 'Ana Reyes', 'Liza Dela Cruz', 'Grace Bautista', 'Jenny Ramos', 'Carla Mendoza', 'Rose Villanueva', 'Tina Garcia', 'Nena Aquino', 'Cecile Navarro', 'Ruth Fernandez', 'Mylene Castillo', 'Lorna Domingo', 'Joy Pascual', 'Elena Soriano', 'Bea Marquez', 'Hazel Ocampo', 'Irene Salazar', 'Wilma Cortez', 'Dina Robles', 'Fe Magsaysay', 'Pia Alcantara', 'Sheila Lim', 'Rina Del Rosario'];
const PRODS = ['Collagen Plus 30s', 'Gluta Max 60s', 'Slim Tea 20 sachets', 'Vita C 1000 100s', 'Probiotic Daily 30s', 'Hair Revive Set'];
function leadRows() {
  // First 88 sample positions are carry-over leads from Nov 5 (shown here as the first rows of the Nov 6 pool).
  const order = ['vip', 'vip', 'twice', 'twice', 'twice', 'rand', 'vip', 'twice', 'rand', 'rand', 'twice', 'rand'];
  const stat = ['', '', 'busy', 'ffup', '', 'rp', '', 'active', '', 'busy', '', 'inactive'];
  return NAMES.map((n, i) => {
    const carry = i % 3 === 0;
    return {
      n, qty: (i % 3) + 1, prod: PRODS[i % PRODS.length], tel: `0917 000 ${String(1200 + i * 37).slice(-4)}`,
      del: `Oct ${(i % 20) + 5}`, days: 12 + ((i * 7) % 25), oos: `Nov ${6 + (i % 9)}`, rec: `Nov ${4 + (i % 7)}`,
      type: i % 4 === 3 ? 'New Customer' : 'CRD Lead', pri: order[i % order.length], carry,
      st: stat[i % stat.length], rp: stat[i % stat.length] === 'rp' ? 'Yes' : (stat[i % stat.length] === 'active' ? 'No' : ''),
      tag: ['Hot', 'Warm', 'Cold', 'High value', ''][i % 5], contact: stat[i % stat.length] ? `Nov ${i % 5 + 1}` : '',
    };
  });
}

function leadTable({ title = 'Leads', rows = 24, stickyNote = true } = {}) {
  const r = leadRows().slice(0, rows);
  const opt = ['Repeat Purchase?', 'Customer Tagging', 'Date of Contact', "Customer's Feedback", 'Callback Date'];
  const body = r.map((x) => {
    const p = PRI[x.pri], s = STATUS[x.st];
    return `<tr>
      <td class="stick">${x.n}</td><td class="r num">${x.qty}</td><td>${x.prod}</td><td class="num">${x.tel}</td>
      <td>${x.del}</td><td class="cn num">${x.days}</td><td>${x.oos}</td><td>${x.rec}</td><td>${x.type}</td>
      <td><span class="chip" style="background:${p[1]};color:${p[2]}">${p[0]}</span></td>
      <td>${x.carry ? '<span class="chip" style="background:#fde3da;color:#a2391b">Carried from Nov 4</span>' : '<span class="muted">Fresh</span>'}</td>
      <td>Joanna Paclibare</td>
      <td>${x.st ? `<span class="chip" style="background:${s[1]};color:${s[2]}">${s[0]}</span>` : '<span class="muted">—</span>'}</td>
      <td>${x.rp || '<span class="muted">—</span>'}</td><td>${x.tag || '<span class="muted">—</span>'}</td><td>${x.contact || '<span class="muted">—</span>'}</td>
    </tr>`;
  }).join('');
  return `<section class="card" style="overflow:hidden" aria-label="${title}">
    <div class="row between pad" style="border-bottom:1px solid var(--line)">
      <div><p class="sm muted"><b class="num" style="color:var(--ink)">250</b> leads on Nov 5 · <span class="num">162</span> worked · <span class="num">88</span> open</p></div>
      <details class="cols"><summary class="btn">Columns</summary><div class="pop">
        <p class="xs muted" style="padding-bottom:6px">Customer Name to Status are always shown.</p>
        ${['Priority', 'Origin', ...opt].map((o) => `<label><input type="checkbox" checked style="accent-color:var(--brand-500)"> ${o}</label>`).join('')}
      </div></details>
    </div>
    ${stickyNote ? `<div class="note info" style="margin:12px 16px 0" id="cra-note"><b>Stays with Joanna all month.</b> Once a lead is assigned to her it remains under her for the whole month, even if she doesn't get to it. Open leads roll into the next day's 250.</div>` : ''}
    <div class="tw" style="margin-top:12px"><table>
      <thead><tr>
        <th class="stick">Customer Name</th><th class="r">Qty</th><th>Product</th><th>Contact #</th><th>Delivered Date</th>
        <th class="yel">Days since Delivered</th><th>Est. Out of Stock</th><th>Recommended Replenishment Day</th><th>Type</th>
        <th class="pri">Priority</th><th class="pri">Origin</th><th>Assigned to</th><th>Status</th>
        ${opt.slice(0, 3).map((o) => `<th class="grn">${o}</th>`).join('')}
      </tr></thead><tbody>${body}</tbody></table></div>
    <div class="tbl-foot"><span>Showing 1–${rows} of 250 leads</span><div class="pg"><span class="on">1</span><span>2</span><span>3</span><span>…</span><span>5</span></div></div>
  </section>`;
}

/* ---- Progress helpers ---- */
function progress(label, a, b, color, { unit = '', pace = null, tail = '', showPct = true } = {}) {
  const p = Math.min(100, (a / b) * 100);
  return `<div><div class="row between sm" style="margin-bottom:4px"><span class="b">${label}</span>
    <span class="num"><b>${fmt(a)}${unit}</b> <span class="muted">/ ${fmt(b)}${unit}${showPct ? ` · ${pct(a, b)}%` : ''}</span></span></div>
    <div class="bar" role="progressbar" aria-label="${label}" aria-valuenow="${a}" aria-valuemin="0" aria-valuemax="${b}"><i style="width:${p}%;background:${color}"></i>${pace != null ? `<span class="mark" style="left:${pace * 100}%" title="Where she should be by now"></span>` : ''}</div>
    ${tail ? `<p class="xs muted" style="margin-top:4px">${tail}</p>` : ''}</div>`;
}

function mixBar(m, total) {
  const seg = (k, v) => `<span class="${PRI[k][3]}" style="flex:${v}" title="${PRI[k][0]}: ${v}">${v > 14 ? v : ''}</span>`;
  return `<div class="mix" role="img" aria-label="Lead mix: ${m.vip} VIP, ${m.twice} handled twice, ${m.rand} random">${seg('vip', m.vip)}${seg('twice', m.twice)}${seg('rand', m.rand)}</div>`;
}
function mixLegend(m) {
  return `<div class="legend" style="margin-top:8px">${['vip', 'twice', 'rand'].map((k, i) => `<span><i class="dot ${PRI[k][3]}"></i>${i + 1}. ${PRI[k][0]} <b class="num">${m[k]}</b></span>`).join('')}</div>`;
}

/* ---- Settings content (shared by modal / drawer / tab) ---- */
function settingsBody() {
  return `<div class="note warn">Only supervisors and leads can see this. A CRA moved here <b>leaves the Segmentation Tracker</b> from the effective date and works only from the Exclusive Lead Tracker.</div>
  <div><h3 class="b" style="margin-bottom:8px">Exclusive CRAs <span class="chip" style="background:var(--brand-100);color:var(--brand-700)">1</span></h3>
    <div class="card" style="box-shadow:none;border:1px solid var(--line);overflow:hidden"><table>
      <thead><tr><th class="stick" style="background:#f7f4f8">CRA</th><th class="r">Leads / day</th><th class="r">Leads / month</th><th>Since</th><th></th></tr></thead>
      <tbody><tr><td class="stick"><span class="row" style="gap:10px"><span class="av sm">JP</span>Joanna Paclibare</span></td><td class="r num">250</td><td class="r num">7,000</td><td>Nov 1</td>
        <td class="r"><button class="btn" type="button" style="height:30px">Return to Segmentation</button></td></tr></tbody></table></div>
    <p class="xs muted" style="margin-top:6px">Joanna is the only exclusive CRA for now. Her leads for the month stay with her if she is returned mid-month.</p></div>
  <div style="border-top:1px solid var(--line);padding-top:16px"><h3 class="b" style="margin-bottom:10px">Transfer a CRA to the Exclusive Lead Tracker</h3>
    <div class="grid" style="gap:12px">
      <label class="field"><span>CRA</span><select class="ctl"><option>Select a CRA…</option><option>Ana Lopez</option><option>Mark Tan</option><option>Carlo Reyes</option></select></label>
      <div class="two">
        <label class="field"><span>Leads per day</span><input class="ctl" value="250" inputmode="numeric"></label>
        <label class="field"><span>Leads per month</span><input class="ctl locked" value="7,000" readonly></label>
        <label class="field"><span>Repeat purchase goal (%)</span><input class="ctl" value="20"></label>
        <label class="field"><span>Confirmed orders goal</span><input class="ctl" value="54"></label>
        <label class="field"><span>AOV target (₱)</span><input class="ctl" value="895"></label>
        <label class="field"><span>Effective date</span><input class="ctl" type="date" value="2026-11-06"></label>
      </div>
      <div><p class="xs b muted" style="margin-bottom:6px">Daily mix priority</p>
        <ol class="order"><li>High AOV / VIP leads</li><li>Leads handled twice</li><li>Random leads</li></ol></div>
    </div></div>`;
}
function settingsFoot(close) {
  return `<button class="btn" type="button" ${close}>Cancel</button><button class="btn primary" type="button" disabled title="Pick a CRA first">Transfer to Exclusive</button>`;
}

/* ---- Mount ---- */
function mount() {
  document.querySelectorAll('[data-sidebar]').forEach((e) => (e.innerHTML = sidebar()));
  document.querySelectorAll('[data-topbar]').forEach((e) => (e.innerHTML = topbar()));
  document.querySelectorAll('[data-filters]').forEach((e) => (e.outerHTML = filters()));
  document.querySelectorAll('[data-lead-table]').forEach((e) => (e.outerHTML = leadTable({ rows: +e.dataset.leadTable || 24 })));
  document.querySelectorAll('[data-settings-body]').forEach((e) => (e.innerHTML = settingsBody()));
  document.querySelectorAll('[data-settings-foot]').forEach((e) => (e.innerHTML = settingsFoot(e.dataset.close || '')));
  document.querySelectorAll('[data-open]').forEach((b) => b.addEventListener('click', () => document.getElementById(b.dataset.open).showModal()));
  document.querySelectorAll('[data-close-dialog]').forEach((b) => b.addEventListener('click', () => b.closest('dialog').close()));
  document.querySelectorAll('dialog').forEach((d) => d.addEventListener('click', (e) => { if (e.target === d) d.close(); }));
  // generic tab groups: [data-tabs] > [role=tab][data-tab], panels [data-panel-group][data-panel]
  document.querySelectorAll('[data-tabs]').forEach((g) => {
    const key = g.dataset.tabs;
    g.querySelectorAll('[data-tab]').forEach((t) => t.addEventListener('click', () => {
      g.querySelectorAll('[data-tab]').forEach((x) => x.setAttribute('aria-selected', x === t));
      document.querySelectorAll(`[data-panel-group="${key}"]`).forEach((p) => (p.hidden = p.dataset.panel !== t.dataset.tab));
    }));
  });
}
document.addEventListener('DOMContentLoaded', mount);

/* Deep links for review, e.g. proposal-3.html#history or #settings-open */
document.addEventListener('DOMContentLoaded', () => {
  const h = location.hash.slice(1);
  if (!h) return;
  if (h === 'settings-open') { document.getElementById('settings')?.showModal(); return; }
  document.querySelector(`[data-tab="${h}"]`)?.click();
});

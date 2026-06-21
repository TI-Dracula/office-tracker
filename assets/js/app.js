/* ============================================================
   Core app: API client, router, modals, drawer, toasts, helpers
   ============================================================ */
const App = (() => {
  const loaders = {};            // view -> load function (registered by modules)
  let currentView = null;

  /* ---------- API ---------- */
  async function api(action, { method = 'GET', body = null, params = {} } = {}) {
    let url = 'api.php?action=' + encodeURIComponent(action);
    for (const [k, v] of Object.entries(params)) {
      if (v !== null && v !== undefined && v !== '') url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(v);
    }
    const opts = { method, headers: {} };
    if (method !== 'GET') {
      opts.headers['Content-Type'] = 'application/json';
      opts.headers['X-CSRF-Token'] = APP.csrf;
      opts.body = body ? JSON.stringify(body) : '{}';
    }
    const res = await fetch(url, opts);
    if (res.status === 401) { location.href = 'index.php'; throw new Error('Session expired'); }
    const data = await res.json().catch(() => ({ ok: false, error: 'Bad server response' }));
    if (!data.ok) throw new Error(data.error || 'Request failed');
    return data;
  }

  async function uploadFile(action, formData) {
    formData.append('csrf', APP.csrf);
    const res = await fetch('api.php?action=' + action, {
      method: 'POST', headers: { 'X-CSRF-Token': APP.csrf }, body: formData
    });
    const data = await res.json().catch(() => ({ ok: false, error: 'Upload failed' }));
    if (!data.ok) throw new Error(data.error || 'Upload failed');
    return data;
  }

  /* ---------- Formatting helpers ---------- */
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  /* ---------- Inline SVG icons (premium line icons — no emojis) ---------- */
  const ICONS = {
    edit:'<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
    trash:'<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M6 6l1 14h10l1-14"/>',
    view:'<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"/><circle cx="12" cy="12" r="3"/>',
    download:'<path d="M12 3v12"/><path d="M7 11l5 5 5-5"/><path d="M5 21h14"/>',
    close:'<path d="M6 6l12 12M18 6 6 18"/>',
    file:'<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8Z"/><path d="M14 3v5h5"/>',
    clip:'<path d="M21 8l-9.5 9.5a4 4 0 0 1-6-6L14 3.5a2.5 2.5 0 0 1 4 4L9 16"/>',
    plus:'<path d="M12 5v14M5 12h14"/>',
    check:'<path d="M20 6 9 17l-5-5"/>',
    alert:'<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/>',
    info:'<circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v4h1"/>',
  };
  function icon(name, cls = 'ic-svg') {
    return `<svg class="${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONS[name] || ''}</svg>`;
  }

  function money(n) {
    const v = Number(n || 0);
    return APP.currency + v.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function moneyShort(n) {
    const v = Number(n || 0);
    if (v >= 1e7) return APP.currency + (v / 1e7).toFixed(2) + ' Cr';
    if (v >= 1e5) return APP.currency + (v / 1e5).toFixed(2) + ' L';
    return money(v);
  }
  const MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  function fmtDate(d) {
    if (!d) return '—';
    const p = String(d).slice(0, 10).split('-');
    if (p.length !== 3) return esc(d);
    return `${+p[2]} ${MONTHS[+p[1]-1]} ${p[0]}`;
  }
  function fmtMonth(ym) { const p = String(ym).split('-'); return MONTHS[+p[1]-1] + ' ' + String(p[0]).slice(2); }

  function daysLabel(n) {
    if (n === null || n === undefined) return '';
    if (n === 0) return 'Today';
    if (n === 1) return 'Tomorrow';
    if (n === -1) return 'Yesterday';
    return n > 0 ? `in ${n} days` : `${Math.abs(n)} days ago`;
  }
  // bucket for countdown colour
  function urgency(n) {
    if (n === null || n === undefined) return '';
    if (n < 0) return 'past';
    if (n <= 7) return 'urgent';
    if (n <= 30) return 'soon';
    return 'ok';
  }

  function fileIcon(_name) { return icon('file'); }
  function fileSize(b) {
    b = Number(b || 0);
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b/1024).toFixed(0) + ' KB';
    return (b/1048576).toFixed(1) + ' MB';
  }
  const STATUS_LABEL = { draft:'Draft', submitted:'Submitted', approved:'Approved', paid:'Paid', rejected:'Rejected',
    open:'Open', in_progress:'In progress', on_hold:'On hold', completed:'Completed' };
  const badge = s => `<span class="badge b-${s}">${STATUS_LABEL[s] || s}</span>`;

  /* ---------- Toast ---------- */
  function toast(msg, type = 'ok') {
    const box = document.getElementById('toasts');
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    const ic = icon(type === 'ok' ? 'check' : type === 'err' ? 'alert' : 'info');
    el.innerHTML = `<span class="ti">${ic}</span><div>${esc(msg)}</div>`;
    box.appendChild(el);
    setTimeout(() => { el.style.transition = '.3s'; el.style.opacity = '0'; el.style.transform = 'translateX(40px)';
      setTimeout(() => el.remove(), 320); }, 3600);
  }

  /* ---------- Modal ---------- */
  function openModal({ title, body, foot = '', wide = false }) {
    const ov = document.getElementById('overlay');
    ov.innerHTML = `<div class="modal ${wide ? 'wide' : ''}" role="dialog">
      <div class="modal-head"><h3>${esc(title)}</h3><div class="x" data-close>&times;</div></div>
      <div class="modal-body">${body}</div>
      ${foot ? `<div class="modal-foot">${foot}</div>` : ''}
    </div>`;
    ov.classList.add('show');
    ov.querySelectorAll('[data-close]').forEach(b => b.onclick = closeModal);
    ov.onclick = e => { if (e.target === ov) closeModal(); };
    return ov.querySelector('.modal');
  }
  function closeModal() { const ov = document.getElementById('overlay'); ov.classList.remove('show'); ov.innerHTML = ''; }

  function confirmDialog(message, { danger = true, okText = 'Delete' } = {}) {
    return new Promise(resolve => {
      openModal({
        title: 'Please confirm',
        body: `<p style="margin:4px 0 0;color:var(--ink-soft)">${esc(message)}</p>`,
        foot: `<button class="btn ghost" data-no>Cancel</button>
               <button class="btn ${danger ? 'danger' : 'primary'}" data-yes>${esc(okText)}</button>`
      });
      const ov = document.getElementById('overlay');
      ov.querySelector('[data-no]').onclick = () => { closeModal(); resolve(false); };
      ov.querySelector('[data-yes]').onclick = () => { closeModal(); resolve(true); };
    });
  }

  /* ---------- Drawer (uses its own backdrop, separate from modal overlay) ---------- */
  function openDrawer(html) {
    const d = document.getElementById('drawer');
    const bd = document.getElementById('drawerBackdrop');
    d.innerHTML = html;
    d.classList.add('show');
    bd.classList.add('show');
    d.querySelectorAll('[data-close]').forEach(b => b.onclick = closeDrawer);
    bd.onclick = closeDrawer;
  }
  function closeDrawer() {
    document.getElementById('drawer').classList.remove('show');
    document.getElementById('drawerBackdrop').classList.remove('show');
  }

  /* ---------- Combobox — styled pick-or-add dropdown (replaces the native datalist) ---------- */
  function combobox(input, options) {
    if (!input || input.dataset.cbx) return;
    input.dataset.cbx = '1';
    input.setAttribute('autocomplete', 'off');
    input.removeAttribute('list');
    const wrap = document.createElement('div');
    wrap.className = 'cbx';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    const panel = document.createElement('div');
    panel.className = 'cbx-panel';
    wrap.appendChild(panel);
    const render = () => {
      const q = input.value.trim().toLowerCase();
      const matches = options.filter(o => String(o).toLowerCase().includes(q));
      const exact = options.some(o => String(o).toLowerCase() === q);
      let html = '';
      if (q && !exact) {
        const v = esc(input.value.trim());
        html += `<div class="cbx-opt cbx-add" data-val="${v}">+ Add &ldquo;${v}&rdquo;</div>`;
      }
      html += matches.map(o => `<div class="cbx-opt" data-val="${esc(o)}">${esc(o)}</div>`).join('');
      panel.innerHTML = html || '<div class="cbx-empty">No matches</div>';
    };
    const open = () => { render(); panel.classList.add('show'); };
    const close = () => panel.classList.remove('show');
    input.addEventListener('focus', open);
    input.addEventListener('input', open);
    input.addEventListener('blur', close);
    input.addEventListener('keydown', e => { if (e.key === 'Escape') { close(); input.blur(); } });
    panel.addEventListener('mousedown', e => {
      const opt = e.target.closest('[data-val]');
      if (!opt) return;
      e.preventDefault();                       // keep focus so blur doesn't fire mid-click
      input.value = opt.getAttribute('data-val');
      close();
    });
  }

  /* ---------- Custom select — replaces the native <select> popup with a themed dropdown ---------- */
  const SELECT_CHEVRON = '<svg class="csel-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
  function select(sel) {
    if (!sel || sel.dataset.cstyled) return;
    sel.dataset.cstyled = '1';
    sel.tabIndex = -1;
    sel.setAttribute('aria-hidden', 'true');
    const wrap = document.createElement('div');
    wrap.className = 'csel';
    ['width', 'minWidth', 'maxWidth'].forEach(p => { if (sel.style[p]) wrap.style[p] = sel.style[p]; });
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(sel);
    sel.classList.add('csel-native');
    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'csel-trigger';
    trigger.setAttribute('aria-haspopup', 'listbox');
    const panel = document.createElement('div');
    panel.className = 'csel-panel';
    panel.setAttribute('role', 'listbox');
    wrap.appendChild(trigger);
    wrap.appendChild(panel);

    const renderTrigger = () => {
      const o = sel.options[sel.selectedIndex];
      trigger.innerHTML = `<span class="csel-val">${esc(o ? o.textContent : '')}</span>${SELECT_CHEVRON}`;
    };
    const renderPanel = () => {
      panel.innerHTML = [...sel.options].map((o, i) =>
        `<div class="csel-opt${i === sel.selectedIndex ? ' sel' : ''}" role="option" data-i="${i}">${esc(o.textContent)}</div>`
      ).join('');
    };
    const close = () => { panel.classList.remove('show'); trigger.classList.remove('open'); };
    const open = () => {
      renderPanel();
      panel.classList.remove('up');
      panel.classList.add('show');
      trigger.classList.add('open');
      const r = trigger.getBoundingClientRect();          // flip up if it would overflow the viewport bottom
      if (r.bottom + panel.offsetHeight + 12 > window.innerHeight && r.top - panel.offsetHeight > 12) panel.classList.add('up');
      const cur = panel.querySelector('.csel-opt.sel');
      if (cur) cur.scrollIntoView({ block: 'nearest' });
    };
    const choose = i => {
      if (i < 0 || i >= sel.options.length) return;
      if (i !== sel.selectedIndex) { sel.selectedIndex = i; sel.dispatchEvent(new Event('change', { bubbles: true })); }
      renderTrigger();
      close();
    };

    trigger.addEventListener('click', e => { e.preventDefault(); panel.classList.contains('show') ? close() : open(); });
    trigger.addEventListener('keydown', e => {
      if (e.key === 'ArrowDown') { e.preventDefault(); choose(Math.min(sel.options.length - 1, sel.selectedIndex + 1)); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); choose(Math.max(0, sel.selectedIndex - 1)); }
      else if (e.key === 'Escape') close();
    });
    panel.addEventListener('mousedown', e => {
      const opt = e.target.closest('[data-i]');
      if (!opt) return;
      e.preventDefault();
      choose(+opt.dataset.i);
    });
    sel.addEventListener('change', renderTrigger);
    sel.addEventListener('csel:refresh', renderTrigger);   // re-sync trigger after a programmatic value change
    renderTrigger();
  }
  function enhanceSelects(root) { (root || document).querySelectorAll('select:not([data-cstyled])').forEach(select); }

  /* ---------- Custom date picker — replaces the native date input + OS calendar ---------- */
  const DP_MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  const DP_DOW = ['Su','Mo','Tu','We','Th','Fr','Sa'];
  const DP_SHORT = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const DP_ICON = '<svg class="dpick-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/></svg>';
  function datepicker(input) {
    if (!input || input.dataset.dpicker) return;
    input.dataset.dpicker = '1';
    const ph = input.getAttribute('placeholder') || input.getAttribute('title') || 'Select date';
    const wrap = document.createElement('div');
    wrap.className = 'dpick';
    ['width', 'minWidth', 'maxWidth'].forEach(p => { if (input.style[p]) wrap.style[p] = input.style[p]; });
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    input.type = 'hidden';
    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'dpick-trigger';
    const panel = document.createElement('div');
    panel.className = 'dpick-panel';
    wrap.appendChild(trigger);
    wrap.appendChild(panel);

    const pad = n => String(n).padStart(2, '0');
    const fmt = v => { const p = (v || '').split('-'); return p.length === 3 ? `${+p[2]} ${DP_SHORT[+p[1] - 1]} ${p[0]}` : ''; };
    const parts = () => { const p = (input.value || '').split('-'); return p.length === 3 ? p.map(Number) : null; };
    let view = { y: 0, m: 0 };

    const renderTrigger = () => {
      const v = input.value;
      trigger.innerHTML = `<span class="dpick-val${v ? '' : ' ph'}">${v ? esc(fmt(v)) : esc(ph)}</span>${DP_ICON}`;
    };
    const build = () => {
      const sel = parts(), now = new Date();
      const tY = now.getFullYear(), tM = now.getMonth(), tD = now.getDate();
      const firstDow = new Date(view.y, view.m, 1).getDay();
      const days = new Date(view.y, view.m + 1, 0).getDate();
      let cells = '';
      for (let i = 0; i < firstDow; i++) cells += '<span class="dpick-day blank"></span>';
      for (let d = 1; d <= days; d++) {
        const isSel = sel && sel[0] === view.y && sel[1] - 1 === view.m && sel[2] === d;
        const isToday = view.y === tY && view.m === tM && d === tD;
        cells += `<button type="button" class="dpick-day${isSel ? ' sel' : ''}${isToday ? ' today' : ''}" data-d="${d}">${d}</button>`;
      }
      panel.innerHTML = `<div class="dpick-head">
          <button type="button" class="dpick-nav" data-nav="-1" aria-label="Previous month">&lsaquo;</button>
          <div class="dpick-mo">${DP_MONTHS[view.m]} ${view.y}</div>
          <button type="button" class="dpick-nav" data-nav="1" aria-label="Next month">&rsaquo;</button>
        </div>
        <div class="dpick-dow">${DP_DOW.map(d => `<span>${d}</span>`).join('')}</div>
        <div class="dpick-grid">${cells}</div>
        <div class="dpick-foot">
          <button type="button" class="dpick-act" data-act="today">Today</button>
          <button type="button" class="dpick-act" data-act="clear">Clear</button>
        </div>`;
    };
    const close = () => { panel.classList.remove('show'); trigger.classList.remove('open'); };
    const open = () => {
      const p = parts(), now = new Date();
      view = { y: p ? p[0] : now.getFullYear(), m: p ? p[1] - 1 : now.getMonth() };
      build();
      panel.classList.add('show'); trigger.classList.add('open');
      const r = trigger.getBoundingClientRect();
      panel.classList.toggle('up', r.bottom + panel.offsetHeight + 12 > window.innerHeight && r.top - panel.offsetHeight > 12);
    };
    const commit = v => { input.value = v; input.dispatchEvent(new Event('change', { bubbles: true })); renderTrigger(); close(); };

    trigger.addEventListener('click', e => { e.preventDefault(); panel.classList.contains('show') ? close() : open(); });
    trigger.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
    panel.addEventListener('mousedown', e => {
      const nav = e.target.closest('[data-nav]'), day = e.target.closest('[data-d]'), act = e.target.closest('[data-act]');
      if (nav) { e.preventDefault(); view.m += +nav.dataset.nav; if (view.m < 0) { view.m = 11; view.y--; } if (view.m > 11) { view.m = 0; view.y++; } build(); }
      else if (day) { e.preventDefault(); commit(`${view.y}-${pad(view.m + 1)}-${pad(+day.dataset.d)}`); }
      else if (act) { e.preventDefault(); if (act.dataset.act === 'clear') commit(''); else { const n = new Date(); commit(`${n.getFullYear()}-${pad(n.getMonth() + 1)}-${pad(n.getDate())}`); } }
    });
    input.addEventListener('csel:refresh', renderTrigger);   // reuse the sync event (Clear etc.)
    renderTrigger();
  }

  /* enhance any select + date input, now and as the DOM changes */
  function enhanceAll(root) {
    if (!root || root.nodeType !== 1) return;
    if (root.tagName === 'SELECT') select(root);
    else if (root.matches && root.matches('input[type="date"]')) datepicker(root);
    if (root.querySelectorAll) {
      root.querySelectorAll('select:not([data-cstyled])').forEach(select);
      root.querySelectorAll('input[type="date"]:not([data-dpicker])').forEach(datepicker);
    }
  }

  /* ---------- Router ---------- */
  function register(view, fn) { loaders[view] = fn; }
  function show(view) {
    if (!document.getElementById('view-' + view)) view = 'dashboard';
    document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
    document.getElementById('view-' + view).classList.add('active');
    document.querySelectorAll('#nav a').forEach(a => a.classList.toggle('active', a.dataset.view === view));
    currentView = view;
    if (location.hash !== '#' + view) history.replaceState(null, '', '#' + view);
    if (loaders[view]) loaders[view]();
  }

  /* ---------- Start ---------- */
  function start() {
    document.querySelectorAll('#nav a').forEach(a => a.onclick = () => show(a.dataset.view));
    document.getElementById('logoutBtn').onclick = async () => {
      try { await api('logout', { method: 'POST' }); } catch (_) {}
      location.href = 'index.php';
    };
    document.addEventListener('keydown', e => {
      if (e.key !== 'Escape') return;
      if (document.getElementById('overlay').classList.contains('show')) closeModal();
      else if (document.getElementById('drawer').classList.contains('show')) closeDrawer();
    });
    enhanceAll(document.body);
    new MutationObserver(ms => {
      for (const m of ms) for (const n of m.addedNodes) enhanceAll(n);
    }).observe(document.body, { childList: true, subtree: true });
    document.addEventListener('mousedown', e => {
      document.querySelectorAll('.csel-trigger.open, .dpick-trigger.open').forEach(t => {
        if (!t.parentNode.contains(e.target)) { t.classList.remove('open'); t.nextElementSibling.classList.remove('show'); }
      });
    });
    const initial = (location.hash || '#dashboard').slice(1);
    show(initial);
  }

  return { api, uploadFile, esc, money, moneyShort, fmtDate, fmtMonth, daysLabel, urgency,
           fileIcon, fileSize, badge, icon, STATUS_LABEL, toast, openModal, closeModal, confirmDialog,
           openDrawer, closeDrawer, combobox, select, enhanceSelects, datepicker, enhanceAll, register, show, start };
})();

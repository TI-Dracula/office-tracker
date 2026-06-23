/* ============================================================
   Custom date picker — replaces the native <input type=date> so
   every month/year is reachable (past invoices, future handovers).
   Usage: mark a field with `data-date`, then call App.wireDates(root)
   after building the DOM. The real value stays ISO (yyyy-mm-dd) on the
   original (now hidden) input, so all existing .value reads keep working.
   ============================================================ */
(() => {
  const MONTHS = ['January','February','March','April','May','June',
                  'July','August','September','October','November','December'];
  const DOW = ['Su','Mo','Tu','We','Th','Fr','Sa'];
  const pad = n => String(n).padStart(2, '0');
  const iso = (y, m, d) => `${y}-${pad(m + 1)}-${pad(d)}`;
  const todayISO = () => { const t = new Date(); return iso(t.getFullYear(), t.getMonth(), t.getDate()); };

  let pop = null;            // shared popup element
  let active = null;         // { hidden, display, y, m }

  function ensurePop() {
    if (pop) return pop;
    pop = document.createElement('div');
    pop.className = 'dp-pop';
    document.body.appendChild(pop);
    pop.addEventListener('click', e => e.stopPropagation());
    return pop;
  }

  function close() {
    if (pop) pop.classList.remove('show');
    active = null;
    document.removeEventListener('click', onDocClick, true);
    document.removeEventListener('keydown', onKey, true);
    window.removeEventListener('scroll', close, true);
  }
  function onDocClick(e) {
    // capture-phase: ignore clicks inside the popup or on its own field
    if (active && e.target !== active.display && !pop.contains(e.target)) close();
  }
  function onKey(e) { if (e.key === 'Escape') close(); }

  function open(hidden, display) {
    ensurePop();
    const cur = (hidden.value || '').slice(0, 10).split('-');
    let y, m;
    if (cur.length === 3) { y = +cur[0]; m = +cur[1] - 1; }
    else { const t = new Date(); y = t.getFullYear(); m = t.getMonth(); }
    active = { hidden, display, y, m };
    render();
    // position under the field
    const r = display.getBoundingClientRect();
    pop.style.visibility = 'hidden';
    pop.classList.add('show');
    const ph = pop.offsetHeight;
    const below = r.bottom + ph + 8 < window.innerHeight || r.top < ph;
    pop.style.left = Math.max(8, Math.min(r.left, window.innerWidth - pop.offsetWidth - 8)) + 'px';
    pop.style.top = (below ? r.bottom + 6 : r.top - ph - 6) + 'px';
    pop.style.visibility = 'visible';
    document.addEventListener('click', onDocClick, true);
    document.addEventListener('keydown', onKey, true);
    window.addEventListener('scroll', close, true);
  }

  function pick(d) {
    active.hidden.value = iso(active.y, active.m, d);
    syncDisplay(active.hidden, active.display);
    active.hidden.dispatchEvent(new Event('change', { bubbles: true }));
    close();
  }
  function clearVal() {
    active.hidden.value = '';
    syncDisplay(active.hidden, active.display);
    active.hidden.dispatchEvent(new Event('change', { bubbles: true }));
    close();
  }
  function goToday() {
    const t = new Date();
    active.y = t.getFullYear(); active.m = t.getMonth();
    pick(t.getDate());
  }
  function shift(delta) {
    let m = active.m + delta, y = active.y;
    if (m < 0) { m = 11; y--; } else if (m > 11) { m = 0; y++; }
    active.m = m; active.y = y; render();
  }

  function render() {
    const { y, m } = active;
    const sel = (active.hidden.value || '').slice(0, 10);
    const today = todayISO();
    const first = new Date(y, m, 1).getDay();
    const days = new Date(y, m + 1, 0).getDate();
    const nowY = new Date().getFullYear();

    let years = '';
    for (let yy = nowY + 15; yy >= 1990; yy--) years += `<option value="${yy}" ${yy === y ? 'selected' : ''}>${yy}</option>`;
    let months = MONTHS.map((nm, i) => `<option value="${i}" ${i === m ? 'selected' : ''}>${nm}</option>`).join('');

    let cells = DOW.map(d => `<span class="dp-dow">${d}</span>`).join('');
    for (let i = 0; i < first; i++) cells += `<span class="dp-cell empty"></span>`;
    for (let d = 1; d <= days; d++) {
      const cellISO = iso(y, m, d);
      const cls = ['dp-cell'];
      if (cellISO === sel) cls.push('sel');
      if (cellISO === today) cls.push('today');
      cells += `<button type="button" class="${cls.join(' ')}" data-d="${d}">${d}</button>`;
    }

    pop.innerHTML = `
      <div class="dp-head">
        <button type="button" class="dp-nav" data-nav="-1">‹</button>
        <div class="dp-sel">
          <select class="dp-month">${months}</select>
          <select class="dp-year">${years}</select>
        </div>
        <button type="button" class="dp-nav" data-nav="1">›</button>
      </div>
      <div class="dp-grid">${cells}</div>
      <div class="dp-foot">
        <button type="button" class="dp-link" data-today>Today</button>
        <button type="button" class="dp-link" data-clear>Clear</button>
      </div>`;

    pop.querySelector('.dp-month').onchange = e => { active.m = +e.target.value; render(); };
    pop.querySelector('.dp-year').onchange = e => { active.y = +e.target.value; render(); };
    pop.querySelectorAll('[data-nav]').forEach(b => b.onclick = () => shift(+b.dataset.nav));
    pop.querySelectorAll('[data-d]').forEach(b => b.onclick = () => pick(+b.dataset.d));
    pop.querySelector('[data-today]').onclick = goToday;
    pop.querySelector('[data-clear]').onclick = clearVal;
  }

  function syncDisplay(hidden, display) {
    display.value = hidden.value ? App.fmtDate(hidden.value) : '';
  }

  /** Convert one [data-date] input into a hidden ISO holder + a readonly display field. */
  function wire(input) {
    if (input.dataset.dp) return;
    input.dataset.dp = '1';
    const display = document.createElement('input');
    display.type = 'text';
    display.readOnly = true;
    display.className = 'dp-display';
    display.placeholder = input.getAttribute('placeholder') || 'Select date';
    input.type = 'hidden';
    input.parentNode.insertBefore(display, input.nextSibling);
    syncDisplay(input, display);
    const openIt = () => open(input, display);
    display.addEventListener('click', openIt);
    display.addEventListener('focus', openIt);
  }

  App.wireDates = function (root) {
    (root || document).querySelectorAll('input[data-date]').forEach(wire);
  };

  /** Programmatically set/clear a wired date field and refresh its visible text. */
  App.setDate = function (input, val) {
    if (!input) return;
    input.value = val || '';
    const d = input.nextElementSibling;
    if (d && d.classList.contains('dp-display')) d.value = input.value ? App.fmtDate(input.value) : '';
  };
})();

/* Dashboard view */
(() => {
  async function load() {
    const box = document.getElementById('dashContent');
    box.innerHTML = '<div class="spin"></div>';
    let d;
    try { d = await App.api('dashboard'); }
    catch (e) { box.innerHTML = `<div class="panel panel-pad muted">Couldn't load dashboard: ${App.esc(e.message)}</div>`; return; }

    const inv = d.invoices, prj = d.projects;
    const maxTrend = Math.max(1, ...inv.trend.map(t => +t.total));

    const trendBars = inv.trend.length
      ? inv.trend.map(t => `<div class="col">
          <div class="amt">${App.moneyShort(t.total)}</div>
          <div class="bar" style="height:${Math.max(4, (+t.total / maxTrend) * 100)}%"></div>
          <div class="cap">${App.fmtMonth(t.ym)}</div></div>`).join('')
      : '<div class="empty" style="margin:auto">No invoice data yet</div>';

    const topV = inv.top_vendors.length
      ? inv.top_vendors.map(v => `<div class="vrow"><div class="nm">${App.esc(v.name)}</div>
          <div class="ct">${v.n}×</div><div class="tt">${App.moneyShort(v.total)}</div></div>`).join('')
      : '<div class="muted tiny" style="padding:14px 0">No vendors yet.</div>';

    const upcoming = prj.upcoming.length
      ? prj.upcoming.map(p => {
          const u = App.urgency(p.days_left);
          return `<div class="vrow"><span class="loc-dot" style="background:${App.esc(p.location_color||'#6ea8fe')}"></span>
            <div class="nm">${App.esc(p.name)}<div class="ct">${App.esc(p.location_code||'')} ${p.tower?'· '+App.esc(p.tower):''}${p.floor?' · Fl '+p.floor:''}</div></div>
            <div class="right"><div class="tt" style="color:var(--${u==='urgent'?'bad':u==='soon'?'warn':'ok'})">${App.daysLabel(p.days_left)}</div>
            <div class="ct">${App.fmtDate(p.handover_date)}</div></div></div>`;
        }).join('')
      : '<div class="muted tiny" style="padding:14px 0">No handovers in the next 30 days.</div>';

    const locBars = prj.per_loc.map(l => {
      const pct = l.total > 0 ? (l.active / Math.max(l.total,1)) * 100 : 0;
      return `<div style="margin-bottom:12px">
        <div class="flex" style="justify-content:space-between;font-size:12.5px;margin-bottom:5px">
          <span class="flex"><span class="loc-dot" style="background:${App.esc(l.color)}"></span> <b>${App.esc(l.code)}</b></span>
          <span class="muted">${l.active} active · ${l.total} total</span></div>
        <div style="height:9px;border-radius:6px;background:rgba(120,140,190,.12);overflow:hidden">
          <div style="height:100%;width:${pct}%;background:${App.esc(l.color)};border-radius:6px;transition:width .6s"></div></div>
      </div>`;
    }).join('');

    box.innerHTML = `
      <div class="stats">
        <div class="stat panel"><div class="lab">Invoices logged</div>
          <div class="val">${inv.count}</div><div class="delta">${App.money(inv.total)} total value</div></div>
        <div class="stat panel"><div class="lab">This month</div>
          <div class="val">${App.moneyShort(inv.month_total)}</div><div class="delta">invoiced this calendar month</div></div>
        <div class="stat panel"><div class="lab">Open projects</div>
          <div class="val">${prj.open}</div><div class="delta">${prj.total} projects total</div></div>
        <div class="stat panel"><div class="lab">Handovers ≤30d</div>
          <div class="val">${prj.upcoming.length}</div><div class="delta">upcoming deadlines</div></div>
      </div>

      <div class="grid2">
        <div class="panel panel-pad">
          <div class="card-title">Invoice value — last 6 months <span class="pill">${App.money(inv.total)} all-time</span></div>
          <div class="chart">${trendBars}</div>
        </div>
        <div class="panel panel-pad">
          <div class="card-title">Top vendors</div>
          <div class="vlist">${topV}</div>
        </div>
      </div>

      <div class="grid2 mt2">
        <div class="panel panel-pad">
          <div class="card-title">Upcoming handovers</div>
          <div class="vlist">${upcoming}</div>
        </div>
        <div class="panel panel-pad">
          <div class="card-title">Active projects by location</div>
          <div class="mt">${locBars || '<div class="muted tiny">No locations.</div>'}</div>
        </div>
      </div>`;
  }

  App.register('dashboard', load);
})();

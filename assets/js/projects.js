/* Projects view — building bars, cards, table, detail drawer */
(() => {
  const state = { tab: 'buildings', sort: 'handover_date', dir: 'asc' };
  let locations = [];
  let projects = [];
  let inited = false;
  let debounce;

  function filters() {
    return {
      q: document.getElementById('prjSearch').value.trim(),
      location_id: document.getElementById('prjLoc').value,
      status: document.getElementById('prjStatus').value,
      open_only: document.getElementById('prjOpenOnly').checked ? 1 : '',
      cs: document.getElementById('prjCS').checked ? 1 : '',
    };
  }

  function init() {
    if (inited) return; inited = true;
    const addBtn = document.getElementById('addProjectBtn');
    if (addBtn) addBtn.onclick = () => openForm();           // null for view-only users
    document.getElementById('prjSearch').oninput = () => { clearTimeout(debounce); debounce = setTimeout(refresh, 300); };
    ['prjLoc','prjStatus'].forEach(id => document.getElementById(id).onchange = refresh);
    document.getElementById('prjOpenOnly').onchange = refresh;
    document.getElementById('prjCS').onchange = refresh;
    document.querySelectorAll('#projTabs button').forEach(b => b.onclick = () => {
      state.tab = b.dataset.tab;
      document.querySelectorAll('#projTabs button').forEach(x => x.classList.toggle('active', x === b));
      renderTab();
    });
    document.querySelectorAll('#view-projects thead th.sortable').forEach(th => th.onclick = () => {
      const s = th.dataset.sort;
      if (state.sort === s) state.dir = state.dir === 'asc' ? 'desc' : 'asc';
      else { state.sort = s; state.dir = 'asc'; }
      refresh();
    });
  }

  async function load() {
    init();
    try {
      const l = await App.api('locations_list'); locations = l.locations;
      const sel = document.getElementById('prjLoc'); const cur = sel.value;
      sel.innerHTML = '<option value="">All locations</option>' +
        locations.map(x => `<option value="${x.id}">${App.esc(x.code)} — ${App.esc(x.name)}</option>`).join('');
      sel.value = cur;
    } catch (_) {}
    refresh();
  }

  async function refresh() {
    try { projects = (await App.api('projects_list', { params: { ...filters(), sort: state.sort, dir: state.dir } })).projects; }
    catch (e) { App.toast(e.message, 'err'); return; }
    renderTab();
  }

  function renderTab() {
    document.getElementById('prjBuildings').classList.toggle('hidden', state.tab !== 'buildings');
    document.getElementById('prjCards').classList.toggle('hidden', state.tab !== 'cards');
    document.getElementById('prjTableWrap').classList.toggle('hidden', state.tab !== 'table');
    if (state.tab === 'buildings') renderBuildings();
    else if (state.tab === 'cards') renderCards();
    else renderTable();
  }

  /* ---------- Floor tooltip (body-level, viewport-positioned, never clipped) ---------- */
  let floorTip = null;
  function ensureFloorTip() {
    if (!floorTip) { floorTip = document.createElement('div'); floorTip.className = 'floortip'; document.body.appendChild(floorTip); }
    return floorTip;
  }
  function showFloorTip(floor) {
    const tip = floor.getAttribute('data-tip'); if (!tip) return;
    const el = ensureFloorTip();
    el.textContent = tip;
    el.classList.add('show'); el.style.opacity = '0';                  // render hidden to measure
    const fr = floor.getBoundingClientRect();
    const w = el.offsetWidth, h = el.offsetHeight, gap = 10;
    let left = fr.right + gap;
    if (left + w > window.innerWidth - 8) left = fr.left - gap - w;     // flip left when no room on the right
    left = Math.max(8, Math.min(left, window.innerWidth - w - 8));
    let top = fr.top + fr.height / 2 - h / 2;
    top = Math.max(8, Math.min(top, window.innerHeight - h - 8));
    el.style.left = left + 'px'; el.style.top = top + 'px'; el.style.opacity = '';
  }
  function hideFloorTip() { if (floorTip) floorTip.classList.remove('show'); }

  /* ---------- BUILDING BARS ---------- */
  function renderBuildings() {
    const wrap = document.getElementById('prjBuildings');
    const locFilter = document.getElementById('prjLoc').value;
    const shown = locFilter ? locations.filter(l => String(l.id) === String(locFilter)) : locations;

    // index projects by location -> "tower|floor"
    const byLoc = {};
    projects.forEach(p => {
      if (!p.location_id) return;
      (byLoc[p.location_id] ??= {});
      const key = (p.tower || '?') + '|' + (p.floor ?? '?');
      (byLoc[p.location_id][key] ??= []).push(p);
    });

    wrap.innerHTML = shown.map(loc => {
      const map = byLoc[loc.id] || {};
      const towers = loc.towers_arr.length ? loc.towers_arr : ['A'];
      let activeCount = 0;

      const towersHtml = towers.map(t => {
        let cells = '';
        for (let fl = 1; fl <= loc.floors; fl++) {
          const arr = (map[t + '|' + fl] || []);
          const active = arr.filter(p => p.is_active);
          const isActive = active.length > 0;
          if (isActive) activeCount += active.length;
          const soon = active.some(p => p.days_left !== null && p.days_left >= 0 && p.days_left <= 30);
          const tip = isActive
            ? active.map(p => `${App.esc(p.name)} · ${p.handover_date ? App.daysLabel(p.days_left) : 'no date'}`).join('\n')
            : '';
          cells += `<div class="floor ${isActive ? 'active' : ''} ${soon ? 'soon' : ''}"
                      ${isActive ? `data-proj="${active[0].id}"` : ''}${tip ? ` data-tip="${tip}"` : ''} style="--bcol:${App.esc(loc.color)}">${isActive ? fl : ''}</div>`;
        }
        return `<div class="tower"><div class="tstack">${cells}</div><div class="tlabel">${App.esc(t)}</div></div>`;
      }).join('');

      // projects that don't fit the configured towers/floors
      const unplaced = (projects.filter(p => p.location_id === loc.id && p.is_active &&
        (!loc.towers_arr.includes(p.tower) || !p.floor || p.floor > loc.floors)));
      const unplacedHtml = unplaced.length
        ? `<div class="mt tiny muted">Also active: ${unplaced.map(p => `<span class="dot-link" data-proj="${p.id}">${App.esc(p.name)}</span>`).join(', ')}</div>` : '';

      return `<div class="building panel" style="--bcol:${App.esc(loc.color)}">
        <div class="bhead">
          <div class="bname">${App.esc(loc.name)}</div>
        </div>
        <div class="bmeta">
          <div><b>${activeCount}</b>active projects</div>
          <div><b>${loc.floors * towers.length}</b>units</div>
        </div>
        <div class="towers">${towersHtml}</div>
        ${unplacedHtml}
        <div class="blegend">
          <span class="k"><span class="sw" style="background:${App.esc(loc.color)}"></span> Active project</span>
          <span class="k"><span class="sw" style="border:1px dashed var(--line-strong)"></span> Vacant</span>
        </div>
      </div>`;
    }).join('') || '<div class="panel empty">No buildings configured.</div>';

    wrap.querySelectorAll('[data-proj]').forEach(el => el.onclick = () => openDrawer(+el.dataset.proj));
    wrap.onmouseover = e => { const f = e.target.closest('.floor.active'); if (f && wrap.contains(f)) showFloorTip(f); };
    wrap.onmouseout  = e => { if (e.target.closest('.floor.active')) hideFloorTip(); };
  }

  /* ---------- CARDS ---------- */
  function renderCards() {
    const wrap = document.getElementById('prjCards');
    const list = projects.slice().sort((a, b) => {
      if (a.handover_date && b.handover_date) return a.handover_date.localeCompare(b.handover_date);
      return a.handover_date ? -1 : 1;
    });
    if (!list.length) { wrap.innerHTML = '<div class="panel empty" style="grid-column:1/-1">No projects yet.</div>'; return; }
    wrap.innerHTML = list.map(p => {
      const u = App.urgency(p.days_left);
      const cd = p.handover_date ? `<div class="countdown ${u}">
          <div><div class="days">${p.days_left>=0?p.days_left:'—'}</div></div>
          <div><div class="cd-lab">${p.days_left<0?'Overdue':'Days to handover'}</div>
          <div class="cd-date">${App.fmtDate(p.handover_date)} · ${App.daysLabel(p.days_left)}</div></div>
        </div>` : `<div class="countdown"><div class="cd-lab">No handover date set</div></div>`;
      return `<div class="pcard panel" data-proj="${p.id}">
        <div class="top">
          <div><div class="nm">${App.esc(p.name)}</div></div>
          <div class="flex" style="gap:6px">${p.is_cs==1?'<span class="badge b-cs">CS</span>':''}${App.badge(p.status)}</div>
        </div>
        <div class="where">
          <span class="loc-chip"><span class="loc-dot" style="background:${App.esc(p.location_color||'#6ea8fe')}"></span>${App.esc(p.location_code||'—')}</span>
          ${p.tower?`<span class="t">Tower ${App.esc(p.tower)}</span>`:''}
          ${p.floor?`<span class="t">Floor ${p.floor}</span>`:''}
          ${p.file_count>0?`<span class="t">${App.icon('clip')} ${p.file_count}</span>`:''}
        </div>
        ${cd}
      </div>`;
    }).join('');
    wrap.querySelectorAll('[data-proj]').forEach(el => el.onclick = () => openDrawer(+el.dataset.proj));
  }

  /* ---------- TABLE ---------- */
  function renderTable() {
    document.querySelectorAll('#view-projects thead th.sortable').forEach(th => {
      const on = th.dataset.sort === state.sort;
      th.classList.toggle('sorted', on);
      th.querySelector('.arr').textContent = on ? (state.dir === 'asc' ? '▲' : '▼') : '';
    });
    const body = document.getElementById('prjBody');
    if (!projects.length) { body.innerHTML = '<tr><td colspan="7" class="empty">No projects match.</td></tr>'; return; }
    body.innerHTML = projects.map(p => {
      const u = App.urgency(p.days_left);
      const hd = p.handover_date
        ? `${App.fmtDate(p.handover_date)} <span class="tiny" style="color:var(--${u==='urgent'?'bad':u==='soon'?'warn':u==='past'?'mut':'ok'})">· ${App.daysLabel(p.days_left)}</span>`
        : '<span class="muted">—</span>';
      const actions = p.can_edit ? `<button class="btn icon sm ghost" data-edit="${p.id}" title="Edit">${App.icon('edit')}</button>
          <button class="btn icon sm danger" data-del="${p.id}" title="Delete">${App.icon('trash')}</button>` : '';
      return `<tr data-proj="${p.id}" style="cursor:pointer">
        <td><b>${App.esc(p.name)}</b>${p.is_cs==1?' <span class="badge b-cs">CS</span>':''}</td>
        <td><span class="loc-chip"><span class="loc-dot" style="background:${App.esc(p.location_color||'#6ea8fe')}"></span>${App.esc(p.location_code||'—')}</span></td>
        <td class="tiny">${p.tower?'Tower '+App.esc(p.tower):'—'}${p.floor?' · Fl '+p.floor:''}</td>
        <td class="nowrap">${hd}</td>
        <td>${App.badge(p.status)}</td>
        <td>${p.file_count>0?`${App.icon('clip')} ${p.file_count}`:'<span class="muted">—</span>'}</td>
        <td><div class="row-actions">${actions}</div></td>
      </tr>`;
    }).join('');
    body.querySelectorAll('tr[data-proj]').forEach(tr => tr.onclick = e => {
      if (e.target.closest('[data-edit],[data-del]')) return;
      openDrawer(+tr.dataset.proj);
    });
    body.querySelectorAll('[data-edit]').forEach(b => b.onclick = () => openForm(+b.dataset.edit));
    body.querySelectorAll('[data-del]').forEach(b => b.onclick = () => del(+b.dataset.del));
  }

  /* ---------- DETAIL DRAWER ---------- */
  async function openDrawer(id) {
    App.openDrawer('<div class="drawer-body"><div class="spin"></div></div>');
    let p;
    try { p = (await App.api('project_get', { params: { id } })).project; }
    catch (e) { return App.toast(e.message, 'err'); }

    const u = App.urgency(p.days_left);
    const cd = p.handover_date ? `<div class="countdown ${u}" style="margin-top:6px">
        <div><div class="days">${p.days_left>=0?p.days_left:Math.abs(p.days_left)}</div></div>
        <div><div class="cd-lab">${p.days_left<0?'Days overdue':'Days to handover'}</div>
        <div class="cd-date">${App.fmtDate(p.handover_date)} · ${App.daysLabel(p.days_left)}</div></div>
      </div>` : '<div class="muted tiny">No handover date set</div>';

    const docs = (p.files && p.files.length)
      ? `<div class="files">${p.files.map(f => `<div class="fileitem">
            <div class="fi">${App.fileIcon(f.original_name)}</div>
            <div class="fn"><b>${App.esc(f.doc_type||'Document')}</b><span>${App.fileSize(f.size)}</span></div>
            <a class="btn sm ghost" href="api.php?action=file_download&type=project&id=${f.id}" target="_blank">View</a>
            ${p.can_edit?`<button class="btn sm danger" data-rmfile="${f.id}" data-type="project">${App.icon('close')}</button>`:''}
          </div>`).join('')}</div>`
      : '<div class="muted tiny">No documents uploaded yet.</div>';

    const uploader = p.can_edit ? `
      <div class="flex mt" style="gap:8px">
        <label class="lbl" style="margin:0">Document type</label>
        <select id="f_doctype" style="max-width:150px"><option>LOI</option><option>Layout</option><option>Agreement</option><option>Quotation</option><option>Other</option></select>
      </div>
      <div class="dropzone mt" data-drop>${App.icon('clip')} Click or drop to upload a document
        <input type="file" hidden data-fileinput></div>` : '';

    // Only surface IT-scope values that are actually set: positive numbers, real text,
    // or a "Yes" boolean. Empty/0/No are omitted, and an empty section is hidden entirely.
    const numv = v => { const n = Number(v); return (v !== null && v !== undefined && v !== '' && Number.isFinite(n) && n > 0) ? String(n) : null; };
    const sChip = (label, val) => (val === null || val === undefined || val === '') ? '' : `<span class="loc-chip">${label}: ${App.esc(String(val))}</span>`;
    const sBlock = (title, chips) => { const body = chips.filter(Boolean).join(''); return body ? `<div class="dlabel">${title}</div><div class="flex" style="gap:8px;flex-wrap:wrap">${body}</div>` : ''; };
    const itScope =
        sBlock('IT scope · Passive cabling', [
          sChip('LAN / workstation', numv(p.lan_per_ws)),
          sChip('Wireless APs', numv(p.wireless_ap)),
          sChip('Meeting-room TVs', numv(p.meeting_tv)),
          sChip('Meeting-room tables', numv(p.meeting_table)),
        ])
      + (p.has_ll == 1 ? sBlock('Internet · Leased line', [
          sChip('Primary', p.ll_primary || null),
          sChip('Secondary', p.ll_secondary || null),
          (!p.ll_primary && !p.ll_secondary) ? '<span class="loc-chip">Required: Yes</span>' : '',
        ]) : '')
      + sBlock('Access control · Spintly', [
          sChip('Push doors', numv(p.spintly_push)),
          sChip('Pull doors', numv(p.spintly_pull)),
          (p.spintly_gateway == 1 ? '<span class="loc-chip">Gateway on floor: Yes</span>' : ''),
        ]);

    App.openDrawer(`
      <div class="drawer-head">
        <div class="flex" style="justify-content:space-between">
          <span class="loc-chip"><span class="loc-dot" style="background:${App.esc(p.location_color||'#6ea8fe')}"></span>${App.esc(p.location_code||'—')}</span>
          <div class="x" data-close>&times;</div>
        </div>
        <h2 style="margin:12px 0 4px;font-size:20px">${App.esc(p.name)}</h2>
        <div class="flex" style="gap:8px">${p.is_cs==1?'<span class="badge b-cs">Customer Success</span>':''}${App.badge(p.status)}</div>
      </div>
      <div class="drawer-body">
        ${cd}
        <div class="dlabel">Location</div>
        <div class="flex" style="gap:8px;flex-wrap:wrap">
          <span class="loc-chip">${App.esc(p.location_name||'—')}</span>
          ${p.tower?`<span class="loc-chip">Tower ${App.esc(p.tower)}</span>`:''}
          ${p.floor?`<span class="loc-chip">Floor ${p.floor}</span>`:''}
        </div>
        ${itScope}
        ${p.notes?`<div class="dlabel">Notes</div><div class="tiny" style="color:var(--ink-soft);white-space:pre-wrap">${App.esc(p.notes)}</div>`:''}
        <div class="dlabel">Documents (LOI &amp; more)</div>
        <div id="dDocs">${docs}</div>
        ${uploader}
        <div class="mt2 flex" style="gap:8px">
          ${p.can_edit?`<button class="btn" id="dEdit">${App.icon('edit')} Edit project</button>`:''}
          ${p.can_edit?`<button class="btn danger" id="dDel">${App.icon('trash')} Delete</button>`:''}
        </div>
        <div class="tiny muted mt2">Added by ${App.esc(p.creator_name||'—')}</div>
      </div>`);

    if (p.can_edit) {
      App._files.wireFileArea(document.getElementById('drawer'), 'project', id, () => openDrawer(id));
      const ed = document.getElementById('dEdit'); if (ed) ed.onclick = () => { App.closeDrawer(); openForm(id); };
      const dd = document.getElementById('dDel'); if (dd) dd.onclick = () => del(id, true);
    }
  }

  /* ---------- Add / edit form ---------- */
  async function openForm(id = 0) {
    let p = { status: 'open', files: [] };
    if (id) { try { p = (await App.api('project_get', { params: { id } })).project; } catch (e) { return App.toast(e.message, 'err'); } }

    const locOpts = locations.map(l => `<option value="${l.id}" ${p.location_id==l.id?'selected':''}>${App.esc(l.code)} — ${App.esc(l.name)}</option>`).join('');
    const statusSel = ['open','in_progress','on_hold','completed']
      .map(s => `<option value="${s}" ${p.status===s?'selected':''}>${App.STATUS_LABEL[s]}</option>`).join('');
    const filesHtml = id ? `<div class="files mt">${(p.files||[]).map(f => App._files.fileRow(f, 'project')).join('')}</div>
        <div class="flex mt" style="gap:8px"><select id="f_doctype" style="max-width:150px"><option>LOI</option><option>Layout</option><option>Agreement</option><option>Quotation</option><option>Other</option></select></div>
        <div class="dropzone mt" data-drop>${App.icon('clip')} Click or drop to upload<input type="file" hidden data-fileinput></div>`
      : '<p class="tiny muted">Save the project first, then upload the LOI &amp; documents.</p>';

    const m = App.openModal({
      title: id ? 'Edit project' : 'Add project', wide: true,
      body: `<div class="formgrid">
        <div class="field full"><label class="lbl">Client / company</label><input id="p_name" value="${App.esc(p.name||'')}" placeholder="e.g. Syndigo / Acme Corporation"></div>
        <div class="field"><label class="lbl">Location</label><select id="p_loc"><option value="">—</option>${locOpts}</select></div>
        <div class="field"><label class="lbl">Tower</label><input id="p_tower" value="${App.esc(p.tower||'')}" placeholder="A" list="towerlist"><datalist id="towerlist"></datalist></div>
        <div class="field"><label class="lbl">Floor</label><input id="p_floor" type="number" min="0" value="${p.floor??''}"></div>
        <div class="field"><label class="lbl">Handover date</label><input id="p_handover" type="date" value="${p.handover_date||''}"></div>
        <div class="field"><label class="lbl">Status</label><select id="p_status">${statusSel}</select></div>
        <div class="field"><label class="lbl">Project type</label><label class="flex" style="gap:9px;padding-top:9px;font-size:13.5px;color:var(--tx-secondary)"><input type="checkbox" id="p_cs" ${p.is_cs==1?'checked':''}> Customer Success</label></div>
        <div class="field full"><label class="lbl">Notes</label><textarea id="p_notes">${App.esc(p.notes||'')}</textarea></div>
        <div class="fsec">Passive cabling</div>
        <div class="field"><label class="lbl">LAN points / workstation</label><input id="p_lan" type="number" min="0" value="${p.lan_per_ws??''}"></div>
        <div class="field"><label class="lbl">Wireless access points</label><input id="p_ap" type="number" min="0" value="${p.wireless_ap??''}"></div>
        <div class="field"><label class="lbl">Meeting-room TVs</label><input id="p_tv" type="number" min="0" value="${p.meeting_tv??''}"></div>
        <div class="field"><label class="lbl">Meeting-room tables</label><input id="p_mtable" type="number" min="0" value="${p.meeting_table??''}"></div>
        <div class="fsec">Internet — leased line</div>
        <div class="field"><label class="lbl">Leased line required?</label><select id="p_ll"><option value="0"${p.has_ll==1?'':' selected'}>No</option><option value="1"${p.has_ll==1?' selected':''}>Yes</option></select></div>
        <div class="field"></div>
        <div class="field"><label class="lbl">Primary LL</label><input id="p_llp" value="${App.esc(p.ll_primary||'')}" placeholder="e.g. 100 Mbps"></div>
        <div class="field"><label class="lbl">Secondary LL</label><input id="p_lls" value="${App.esc(p.ll_secondary||'')}" placeholder="e.g. 50 Mbps"></div>
        <div class="fsec">Access control — Spintly</div>
        <div class="field"><label class="lbl">Push doors</label><input id="p_push" type="number" min="0" value="${p.spintly_push??''}"></div>
        <div class="field"><label class="lbl">Pull doors</label><input id="p_pull" type="number" min="0" value="${p.spintly_pull??''}"></div>
        <div class="field"><label class="lbl">Gateway already on floor?</label><select id="p_gw"><option value="0"${p.spintly_gateway==1?'':' selected'}>No</option><option value="1"${p.spintly_gateway==1?' selected':''}>Yes</option></select></div>
        <div class="field full"><label class="lbl">Documents</label><div id="f_files_p">${filesHtml}</div></div>
      </div>`,
      foot: `<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="p_save">${id?'Save changes':'Add project'}</button>`
    });

    // tower suggestions from chosen location
    const towerDL = m.querySelector('#towerlist');
    const fillTowers = () => {
      const loc = locations.find(l => String(l.id) === m.querySelector('#p_loc').value);
      towerDL.innerHTML = (loc?.towers_arr || []).map(t => `<option value="${App.esc(t)}">`).join('');
    };
    m.querySelector('#p_loc').onchange = fillTowers; fillTowers();

    async function refreshFormDocs() {
      const np = (await App.api('project_get', { params: { id } })).project;
      m.querySelector('#f_files_p').innerHTML = `<div class="files mt">${(np.files||[]).map(f => App._files.fileRow(f,'project')).join('')}</div>
        <div class="flex mt" style="gap:8px"><select id="f_doctype" style="max-width:150px"><option>LOI</option><option>Layout</option><option>Agreement</option><option>Quotation</option><option>Other</option></select></div>
        <div class="dropzone mt" data-drop>${App.icon('clip')} Click or drop to upload<input type="file" hidden data-fileinput></div>`;
      App._files.wireFileArea(m, 'project', id, refreshFormDocs);
    }
    if (id) App._files.wireFileArea(m, 'project', id, refreshFormDocs);

    m.querySelector('#p_save').onclick = async () => {
      const name = m.querySelector('#p_name').value.trim();
      if (!name) return App.toast('Client / company is required.', 'err');
      const payload = { id, name, location_id: m.querySelector('#p_loc').value,
        tower: m.querySelector('#p_tower').value, floor: m.querySelector('#p_floor').value,
        handover_date: m.querySelector('#p_handover').value, status: m.querySelector('#p_status').value,
        notes: m.querySelector('#p_notes').value, is_cs: m.querySelector('#p_cs').checked ? 1 : 0,
        lan_per_ws: m.querySelector('#p_lan').value, wireless_ap: m.querySelector('#p_ap').value,
        meeting_tv: m.querySelector('#p_tv').value, meeting_table: m.querySelector('#p_mtable').value,
        has_ll: m.querySelector('#p_ll').value, ll_primary: m.querySelector('#p_llp').value, ll_secondary: m.querySelector('#p_lls').value,
        spintly_push: m.querySelector('#p_push').value, spintly_pull: m.querySelector('#p_pull').value, spintly_gateway: m.querySelector('#p_gw').value };
      const btn = m.querySelector('#p_save'); btn.disabled = true; btn.textContent = 'Saving…';
      try {
        const r = await App.api('project_save', { method: 'POST', body: payload });
        App.toast(id ? 'Project updated' : 'Project added', 'ok');
        if (!id) { App.closeModal(); await refresh(); openForm(r.id); }
        else { App.closeModal(); refresh(); }
      } catch (e) { App.toast(e.message, 'err'); btn.disabled = false; btn.textContent = id?'Save changes':'Add project'; }
    };
  }

  async function del(id, fromDrawer = false) {
    if (!await App.confirmDialog('Delete this project and all its documents? This cannot be undone.')) return;
    try { await App.api('project_delete', { method: 'POST', body: { id } }); App.toast('Project deleted', 'ok');
      if (fromDrawer) App.closeDrawer(); refresh(); }
    catch (e) { App.toast(e.message, 'err'); }
  }

  App.register('projects', load);
  App._projects = { reloadLocations: load };
})();

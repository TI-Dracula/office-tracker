/* Asset Management — equipment, assignment to people, and history. */
(() => {
  const state = { sort: 'name', dir: 'asc' };
  const STATUSES = ['in_use', 'in_stock', 'repair', 'retired'];
  const CATEGORIES = ['Laptop', 'Desktop', 'Monitor', 'Phone', 'Access Card', 'Furniture', 'Networking', 'Peripheral', 'Other'];
  let assets = [], people = [], locations = [];
  let inited = false, debounce;

  function filters() {
    return {
      q:        document.getElementById('assetSearch').value.trim(),
      status:   document.getElementById('assetStatus').value,
      assigned: document.getElementById('assetAssigned').value,
    };
  }

  function init() {
    if (inited) return; inited = true;
    document.getElementById('addAssetBtn').onclick = () => openForm();
    document.getElementById('assetSearch').oninput = () => { clearTimeout(debounce); debounce = setTimeout(refresh, 300); };
    ['assetStatus', 'assetAssigned'].forEach(id => document.getElementById(id).onchange = refresh);
    document.getElementById('assetExport').onclick = () => { window.location = 'api.php?action=assets_export'; };
    document.querySelectorAll('#assetTable thead th.sortable').forEach(th => th.onclick = () => {
      const s = th.dataset.sort;
      if (state.sort === s) state.dir = state.dir === 'asc' ? 'desc' : 'asc';
      else { state.sort = s; state.dir = 'asc'; }
      refresh();
    });
  }

  async function load() {
    init();
    try {
      people = (await App.api('people_list')).people;
      locations = (await App.api('locations_list')).locations;
    } catch (_) {}
    // assignee filter options
    const sel = document.getElementById('assetAssigned'); const cur = sel.value;
    sel.innerHTML = '<option value="">Anyone</option><option value="unassigned">Unassigned</option>' +
      people.filter(p => p.active).map(p => `<option value="${p.id}">${App.esc(p.display_name)}</option>`).join('');
    sel.value = cur;
    refresh();
  }

  async function refresh() {
    const body = document.getElementById('assetBody');
    body.innerHTML = `<tr><td colspan="7"><div class="spin"></div></td></tr>`;
    try { assets = (await App.api('assets_list', { params: { ...filters(), sort: state.sort, dir: state.dir } })).assets; }
    catch (e) { body.innerHTML = `<tr><td colspan="7" class="empty">${App.esc(e.message)}</td></tr>`; return; }

    document.querySelectorAll('#assetTable thead th.sortable').forEach(th => {
      const on = th.dataset.sort === state.sort;
      th.classList.toggle('sorted', on);
      th.querySelector('.arr').textContent = on ? (state.dir === 'asc' ? '▲' : '▼') : '';
    });

    // summary
    const n = assets.length;
    const inUse = assets.filter(a => a.status === 'in_use').length;
    const unassigned = assets.filter(a => !a.assigned_person_id).length;
    document.getElementById('assetSummary').innerHTML =
      `<div class="it"><b>${n}</b><span>Assets (filtered)</span></div>
       <div class="it"><b>${inUse}</b><span>In use</span></div>
       <div class="it"><b>${unassigned}</b><span>Unassigned</span></div>`;

    if (!n) {
      body.innerHTML = `<tr><td colspan="7"><div class="empty"><div class="big">💻</div>No assets match. <span class="dot-link" id="emptyAdd">Add one →</span></div></td></tr>`;
      const ea = document.getElementById('emptyAdd'); if (ea) ea.onclick = () => openForm();
      return;
    }

    body.innerHTML = assets.map(a => {
      const actions = a.can_edit
        ? `<button class="btn icon sm ghost" title="Edit" data-edit="${a.id}">✎</button>
           <button class="btn icon sm danger" title="Delete" data-del="${a.id}">🗑</button>` : '';
      return `<tr data-asset="${a.id}" style="cursor:pointer">
        <td><b>${App.esc(a.name)}</b>${a.asset_tag ? `<div class="tiny muted">#${App.esc(a.asset_tag)}</div>` : ''}</td>
        <td>${a.category ? App.esc(a.category) : '<span class="muted">—</span>'}</td>
        <td class="tiny muted">${App.esc(a.serial_no || '—')}</td>
        <td>${a.assignee_name ? App.esc(a.assignee_name) : '<span class="muted">Unassigned</span>'}</td>
        <td class="nowrap tiny">${a.assigned_on ? App.fmtDate(a.assigned_on) : '<span class="muted">—</span>'}</td>
        <td>${App.badge(a.status)}</td>
        <td><div class="row-actions">${actions}</div></td>
      </tr>`;
    }).join('');

    body.querySelectorAll('tr[data-asset]').forEach(tr => tr.onclick = e => {
      if (e.target.closest('[data-edit],[data-del]')) return;
      openDrawer(+tr.dataset.asset);
    });
    body.querySelectorAll('[data-edit]').forEach(b => b.onclick = () => openForm(+b.dataset.edit));
    body.querySelectorAll('[data-del]').forEach(b => b.onclick = () => del(+b.dataset.del));
  }

  const EVENT_ICON = { created: '➕', assigned: '👤', returned: '↩️', status_change: '🔁', note: '📝' };

  async function openDrawer(id) {
    App.openDrawer('<div class="drawer-body"><div class="spin"></div></div>');
    let a;
    try { a = (await App.api('asset_get', { params: { id } })).asset; }
    catch (e) { return App.toast(e.message, 'err'); }

    const history = (a.events && a.events.length)
      ? `<div class="timeline">${a.events.map(ev => `<div class="tl-item">
          <div class="tl-ic">${EVENT_ICON[ev.event_type] || '•'}</div>
          <div><div class="tl-main">${App.esc(ev.detail || ev.event_type)}</div>
          <div class="tiny muted">${ev.event_date ? App.fmtDate(ev.event_date) : ''}${ev.by_name ? ' · by ' + App.esc(ev.by_name) : ''}</div></div>
        </div>`).join('')}</div>`
      : '<div class="muted tiny">No history yet.</div>';

    App.openDrawer(`
      <div class="drawer-head">
        <div class="flex" style="justify-content:space-between">
          ${App.badge(a.status)}<div class="x" data-close>&times;</div>
        </div>
        <h2 style="margin:12px 0 4px;font-size:20px">${App.esc(a.name)}</h2>
        <div class="tiny muted">${a.asset_tag ? '#' + App.esc(a.asset_tag) : ''}${a.category ? ' · ' + App.esc(a.category) : ''}</div>
      </div>
      <div class="drawer-body">
        <div class="dlabel">Assigned to</div>
        ${a.assignee_name
          ? `<div class="flex" style="gap:8px"><span class="loc-chip">👤 ${App.esc(a.assignee_name)}</span>${a.assigned_on ? `<span class="muted tiny">since ${App.fmtDate(a.assigned_on)}</span>` : ''}</div>
             ${a.assignee_email ? `<div class="tiny muted mt">${App.esc(a.assignee_email)}</div>` : ''}`
          : '<div class="muted tiny">Unassigned</div>'}
        <div class="flex" style="gap:8px;flex-wrap:wrap;margin-top:10px">
          ${a.serial_no ? `<span class="loc-chip">S/N ${App.esc(a.serial_no)}</span>` : ''}
          ${a.location_code ? `<span class="loc-chip"><span class="loc-dot" style="background:${App.esc(a.location_color || '#6ea8fe')}"></span>${App.esc(a.location_code)}</span>` : ''}
        </div>
        ${a.notes ? `<div class="dlabel">Notes</div><div class="tiny" style="color:var(--ink-soft);white-space:pre-wrap">${App.esc(a.notes)}</div>` : ''}
        <div class="dlabel">History</div>
        ${history}
        <div class="mt2 flex" style="gap:8px">
          ${a.can_edit ? `<button class="btn" id="aEdit">✎ Edit</button><button class="btn danger" id="aDel">🗑 Delete</button>` : ''}
        </div>
        <div class="tiny muted mt2">Added by ${App.esc(a.creator_name || '—')}</div>
      </div>`);

    if (a.can_edit) {
      document.getElementById('aEdit').onclick = () => { App.closeDrawer(); openForm(id); };
      document.getElementById('aDel').onclick = () => del(id, true);
    }
  }

  async function openForm(id = 0) {
    let a = { status: 'in_stock' };
    if (id) { try { a = (await App.api('asset_get', { params: { id } })).asset; } catch (e) { return App.toast(e.message, 'err'); } }

    const statusSel = STATUSES.map(s => `<option value="${s}" ${a.status === s ? 'selected' : ''}>${App.STATUS_LABEL[s]}</option>`).join('');
    const catOpts = CATEGORIES.map(c => `<option value="${App.esc(c)}">`).join('');
    const peopleOpts = '<option value="">— Unassigned —</option>' +
      people.filter(p => p.active || p.id == a.assigned_person_id)
            .map(p => `<option value="${p.id}" ${a.assigned_person_id == p.id ? 'selected' : ''}>${App.esc(p.display_name)}</option>`).join('');
    const locOpts = '<option value="">—</option>' +
      locations.map(l => `<option value="${l.id}" ${a.location_id == l.id ? 'selected' : ''}>${App.esc(l.code)}</option>`).join('');

    const m = App.openModal({
      title: id ? 'Edit asset' : 'Add asset', wide: true,
      body: `<div class="formgrid">
        <div class="field"><label class="lbl">Asset name</label><input id="a_name" value="${App.esc(a.name || '')}" placeholder="e.g. Dell Latitude 5440"></div>
        <div class="field"><label class="lbl">Asset tag</label><input id="a_tag" value="${App.esc(a.asset_tag || '')}" placeholder="IBC-0001"></div>
        <div class="field"><label class="lbl">Category</label><input id="a_cat" list="acatlist" value="${App.esc(a.category || '')}"><datalist id="acatlist">${catOpts}</datalist></div>
        <div class="field"><label class="lbl">Serial number</label><input id="a_serial" value="${App.esc(a.serial_no || '')}"></div>
        <div class="field"><label class="lbl">Status</label><select id="a_status">${statusSel}</select></div>
        <div class="field"><label class="lbl">Location</label><select id="a_loc">${locOpts}</select></div>
        <div class="field"><label class="lbl">Assigned to</label><select id="a_person">${peopleOpts}</select></div>
        <div class="field"><label class="lbl">Assigned on</label><input id="a_on" data-date value="${a.assigned_on || ''}"></div>
        <div class="field full"><label class="lbl">Notes</label><textarea id="a_notes">${App.esc(a.notes || '')}</textarea></div>
      </div>
      <p class="tiny muted">Need someone who isn't listed? Add them under <b>Directory</b> (admins) or sync from Microsoft 365.</p>`,
      foot: `<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="a_save">${id ? 'Save changes' : 'Add asset'}</button>`
    });

    App.wireDates(m);
    // default the assigned-on date to today when a person is first chosen
    m.querySelector('#a_person').onchange = e => {
      const on = m.querySelector('#a_on');
      if (e.target.value && !on.value) App.setDate(on, new Date().toISOString().slice(0, 10));
    };

    m.querySelector('#a_save').onclick = async () => {
      const name = m.querySelector('#a_name').value.trim();
      if (!name) return App.toast('Asset name is required.', 'err');
      const payload = {
        id, name,
        asset_tag: m.querySelector('#a_tag').value,
        category: m.querySelector('#a_cat').value,
        serial_no: m.querySelector('#a_serial').value,
        status: m.querySelector('#a_status').value,
        location_id: m.querySelector('#a_loc').value,
        assigned_person_id: m.querySelector('#a_person').value,
        assigned_on: m.querySelector('#a_on').value,
        notes: m.querySelector('#a_notes').value,
      };
      const btn = m.querySelector('#a_save'); btn.disabled = true; btn.textContent = 'Saving…';
      try {
        await App.api('asset_save', { method: 'POST', body: payload });
        App.toast(id ? 'Asset updated' : 'Asset added', 'ok');
        App.closeModal(); refresh();
      } catch (e) { App.toast(e.message, 'err'); btn.disabled = false; btn.textContent = id ? 'Save changes' : 'Add asset'; }
    };
  }

  async function del(id, fromDrawer = false) {
    if (!await App.confirmDialog('Delete this asset and its history? This cannot be undone.')) return;
    try {
      await App.api('asset_delete', { method: 'POST', body: { id } });
      App.toast('Asset deleted', 'ok');
      if (fromDrawer) App.closeDrawer();
      refresh();
    } catch (e) { App.toast(e.message, 'err'); }
  }

  App.register('assets', load);
})();

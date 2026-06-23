/* Admin views — Buildings, Users, Settings (only loaded for admins) */
(() => {

  /* ---------- Buildings editor ---------- */
  async function loadBuildings() {
    const box = document.getElementById('bldEditor');
    box.innerHTML = '<div class="spin"></div>';
    let locs;
    try { locs = (await App.api('locations_list')).locations; }
    catch (e) { box.innerHTML = `<div class="panel empty">${App.esc(e.message)}</div>`; return; }

    box.innerHTML = locs.map(l => `
      <div class="building panel" style="--bcol:${App.esc(l.color)}" data-id="${l.id}">
        <div class="bhead">
          <div class="bcode">${App.esc(l.code)}</div>
          <div><div class="bname">${App.esc(l.name)}</div><div class="bsub">Building configuration</div></div>
        </div>
        <div class="field mt"><label class="lbl">Display name</label><input data-f="name" value="${App.esc(l.name)}"></div>
        <div class="formgrid mt">
          <div class="field"><label class="lbl">Towers (comma separated)</label><input data-f="towers" value="${App.esc(l.towers_arr.join(','))}" placeholder="A,B,C"></div>
          <div class="field"><label class="lbl">Floors</label><input data-f="floors" type="number" min="1" max="100" value="${l.floors}"></div>
        </div>
        <div class="formgrid mt">
          <div class="field"><label class="lbl">Accent colour</label><input data-f="color" type="color" value="${App.esc(l.color)}" style="height:42px;padding:4px"></div>
          <div class="field"><label class="lbl">Google Maps link</label><input data-f="maps_url" value="${App.esc(l.maps_url||'')}"></div>
        </div>
        <div class="mt"><button class="btn primary sm" data-save="${l.id}">Save ${App.esc(l.code)}</button></div>
      </div>`).join('');

    box.querySelectorAll('[data-save]').forEach(b => b.onclick = async () => {
      const card = b.closest('[data-id]');
      const val = f => card.querySelector(`[data-f="${f}"]`).value;
      b.disabled = true; b.textContent = 'Saving…';
      try {
        await App.api('location_save', { method: 'POST', body: {
          id: +card.dataset.id, name: val('name'), towers: val('towers'),
          floors: val('floors'), color: val('color'), maps_url: val('maps_url')
        }});
        App.toast('Building updated', 'ok');
      } catch (e) { App.toast(e.message, 'err'); }
      b.disabled = false; b.textContent = 'Save ' + card.querySelector('.bcode').textContent;
    });
  }

  /* ---------- Users ---------- */
  async function loadUsers() {
    const body = document.getElementById('usersBody');
    body.innerHTML = '<tr><td colspan="6"><div class="spin"></div></td></tr>';
    document.getElementById('addUserBtn').onclick = () => userForm();
    let users;
    try { users = (await App.api('users_list')).users; }
    catch (e) { body.innerHTML = `<tr><td colspan="6" class="empty">${App.esc(e.message)}</td></tr>`; return; }

    body.innerHTML = users.map(u => `<tr>
      <td><b>${App.esc(u.name)}</b></td>
      <td class="muted">${App.esc(u.username)}</td>
      <td class="muted">${App.esc(u.email||'—')}</td>
      <td>${u.role==='admin'?'<span class="badge b-approved">Admin</span>':u.role==='viewer'?'<span class="badge b-draft">View only</span>':'<span class="badge b-submitted">Member</span>'}</td>
      <td>${u.active==1?'<span class="badge b-paid">Active</span>':'<span class="badge b-rejected">Disabled</span>'}</td>
      <td><div class="row-actions">
        <button class="btn icon sm ghost" data-edit="${u.id}" title="Edit">✎</button>
        ${u.id!=APP.user.id?`<button class="btn icon sm danger" data-del="${u.id}" title="Delete">🗑</button>`:''}
      </div></td></tr>`).join('');
    body.querySelectorAll('[data-edit]').forEach(b => b.onclick = () => userForm(users.find(x => x.id == b.dataset.edit)));
    body.querySelectorAll('[data-del]').forEach(b => b.onclick = async () => {
      if (!await App.confirmDialog('Delete this user? Their records stay, but they lose access.')) return;
      try { await App.api('user_delete', { method: 'POST', body: { id: +b.dataset.del } }); App.toast('User deleted', 'ok'); loadUsers(); }
      catch (e) { App.toast(e.message, 'err'); }
    });
  }

  function userForm(u = null) {
    const m = App.openModal({
      title: u ? 'Edit user' : 'Add user',
      body: `<div class="formgrid">
        <div class="field"><label class="lbl">Full name</label><input id="u_name" value="${App.esc(u?.name||'')}"></div>
        <div class="field"><label class="lbl">Username</label><input id="u_user" value="${App.esc(u?.username||'')}"></div>
        <div class="field"><label class="lbl">Email (optional)</label><input id="u_email" value="${App.esc(u?.email||'')}"></div>
        <div class="field"><label class="lbl">Role</label><select id="u_role">
          <option value="viewer" ${u?.role==='viewer'?'selected':''}>View only (Projects &amp; Brochures — no pricing)</option>
          <option value="member" ${(!u||u.role==='member')?'selected':''}>Member (add &amp; edit own)</option>
          <option value="admin" ${u?.role==='admin'?'selected':''}>Admin (full control)</option></select></div>
        <div class="field"><label class="lbl">${u?'New password (leave blank to keep)':'Password'}</label><input id="u_pass" type="password"></div>
        <div class="field"><label class="lbl">Status</label><select id="u_active">
          <option value="1" ${(!u||u.active==1)?'selected':''}>Active</option>
          <option value="0" ${u&&u.active==0?'selected':''}>Disabled</option></select></div>
      </div>`,
      foot: `<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="u_save">${u?'Save':'Create user'}</button>`
    });
    m.querySelector('#u_save').onclick = async () => {
      const body = { id: u?.id || 0, name: m.querySelector('#u_name').value, username: m.querySelector('#u_user').value,
        email: m.querySelector('#u_email').value, role: m.querySelector('#u_role').value,
        password: m.querySelector('#u_pass').value, active: +m.querySelector('#u_active').value };
      try { await App.api('user_save', { method: 'POST', body }); App.toast('Saved', 'ok'); App.closeModal(); loadUsers(); }
      catch (e) { App.toast(e.message, 'err'); }
    };
  }

  /* ---------- Settings ---------- */
  async function loadSettings() {
    let s;
    try { s = (await App.api('settings_get')).settings; } catch (e) { return App.toast(e.message, 'err'); }
    document.getElementById('setAppName').value = s.app_name || '';
    document.getElementById('setCurrency').value = s.currency_symbol || '₹';
    document.getElementById('saveSettings').onclick = async () => {
      try {
        await App.api('settings_save', { method: 'POST', body: {
          app_name: document.getElementById('setAppName').value,
          currency_symbol: document.getElementById('setCurrency').value
        }});
        APP.currency = document.getElementById('setCurrency').value;
        App.toast('Settings saved', 'ok');
      } catch (e) { App.toast(e.message, 'err'); }
    };
  }

  /* ---------- Directory (people + Microsoft 365 sync) ---------- */
  async function loadDirectory() {
    document.getElementById('addPersonBtn').onclick = () => personForm();

    // M365 status panel
    try {
      const s = await App.api('m365_status');
      renderM365(s);
    } catch (e) {
      document.getElementById('m365Panel').innerHTML = `<div class="empty">${App.esc(e.message)}</div>`;
    }

    // People table
    const body = document.getElementById('peopleBody');
    body.innerHTML = '<tr><td colspan="8"><div class="spin"></div></td></tr>';
    let people;
    try { people = (await App.api('people_list')).people; }
    catch (e) { body.innerHTML = `<tr><td colspan="8" class="empty">${App.esc(e.message)}</td></tr>`; return; }

    if (!people.length) {
      body.innerHTML = '<tr><td colspan="8" class="empty">No people yet. Add someone, or sync from Microsoft 365.</td></tr>';
      return;
    }
    body.innerHTML = people.map(p => `<tr>
      <td><b>${App.esc(p.display_name)}</b></td>
      <td class="muted">${App.esc(p.email || '—')}</td>
      <td class="muted">${App.esc(p.job_title || '—')}</td>
      <td class="muted">${App.esc(p.department || '—')}</td>
      <td>${p.is_m365 ? '<span class="badge b-approved">M365</span>' : '<span class="badge b-submitted">Manual</span>'}</td>
      <td>${p.asset_count || 0}</td>
      <td>${p.active ? '<span class="badge b-paid">Active</span>' : '<span class="badge b-rejected">Inactive</span>'}</td>
      <td><div class="row-actions">
        <button class="btn icon sm ghost" data-edit="${p.id}" title="Edit">✎</button>
        <button class="btn icon sm danger" data-del="${p.id}" title="Delete">🗑</button>
      </div></td></tr>`).join('');

    body.querySelectorAll('[data-edit]').forEach(b => b.onclick = () => personForm(people.find(x => x.id == b.dataset.edit)));
    body.querySelectorAll('[data-del]').forEach(b => b.onclick = async () => {
      if (!await App.confirmDialog('Delete this person? Assets assigned to them become unassigned.')) return;
      try { await App.api('person_delete', { method: 'POST', body: { id: +b.dataset.del } }); App.toast('Person deleted', 'ok'); loadDirectory(); }
      catch (e) { App.toast(e.message, 'err'); }
    });
  }

  function renderM365(s) {
    const panel = document.getElementById('m365Panel');
    if (s.enabled) {
      panel.innerHTML = `<div class="flex" style="justify-content:space-between;flex-wrap:wrap;gap:10px">
        <div><b>🟢 Microsoft 365 connected</b>
          <div class="tiny muted mt">${s.counts.m365} synced · ${s.counts.manual} manual · ${s.last_sync ? 'last sync ' + App.fmtDate(s.last_sync) : 'never synced'}</div></div>
        <button class="btn primary sm" id="m365Sync">⟳ Sync now</button></div>`;
      document.getElementById('m365Sync').onclick = syncM365;
    } else {
      panel.innerHTML = `<div><b>Microsoft 365 — not connected</b>
        <div class="tiny muted mt">Add your Entra app credentials to the <code>m365</code> block in <code>config.php</code> and set <code>enabled =&gt; true</code> to pull your staff list in automatically. Until then, add people manually below.</div></div>`;
    }
  }

  async function syncM365() {
    const btn = document.getElementById('m365Sync'); btn.disabled = true; btn.textContent = 'Syncing…';
    try { const r = await App.api('m365_sync', { method: 'POST' }); App.toast('Synced ' + r.synced + ' people from Microsoft 365', 'ok'); loadDirectory(); }
    catch (e) { App.toast(e.message, 'err'); btn.disabled = false; btn.textContent = '⟳ Sync now'; }
  }

  function personForm(p = null) {
    const m = App.openModal({
      title: p ? 'Edit person' : 'Add person',
      body: `<div class="formgrid">
        <div class="field"><label class="lbl">Full name</label><input id="pe_name" value="${App.esc(p?.display_name || '')}"></div>
        <div class="field"><label class="lbl">Email</label><input id="pe_email" value="${App.esc(p?.email || '')}"></div>
        <div class="field"><label class="lbl">Job title</label><input id="pe_title" value="${App.esc(p?.job_title || '')}"></div>
        <div class="field"><label class="lbl">Department</label><input id="pe_dept" value="${App.esc(p?.department || '')}"></div>
        <div class="field"><label class="lbl">Status</label><select id="pe_active">
          <option value="1" ${(!p || p.active == 1) ? 'selected' : ''}>Active</option>
          <option value="0" ${p && p.active == 0 ? 'selected' : ''}>Inactive</option></select></div>
      </div>${p && p.is_m365 ? '<p class="tiny muted">Synced from Microsoft 365 — manual edits may be overwritten on the next sync.</p>' : ''}`,
      foot: `<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="pe_save">${p ? 'Save' : 'Add person'}</button>`
    });
    m.querySelector('#pe_save').onclick = async () => {
      const body = {
        id: p?.id || 0,
        display_name: m.querySelector('#pe_name').value,
        email: m.querySelector('#pe_email').value,
        job_title: m.querySelector('#pe_title').value,
        department: m.querySelector('#pe_dept').value,
        active: +m.querySelector('#pe_active').value,
      };
      if (!body.display_name.trim()) return App.toast('A name is required.', 'err');
      try { await App.api('person_save', { method: 'POST', body }); App.toast('Saved', 'ok'); App.closeModal(); loadDirectory(); }
      catch (e) { App.toast(e.message, 'err'); }
    };
  }

  App.register('buildings', loadBuildings);
  App.register('users', loadUsers);
  App.register('settings', loadSettings);
  App.register('directory', loadDirectory);
})();

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
      <td>${u.role==='admin'?'<span class="badge b-approved">Admin</span>':'<span class="badge b-submitted">Member</span>'}</td>
      <td>${u.active==1?'<span class="badge b-paid">Active</span>':'<span class="badge b-rejected">Disabled</span>'}</td>
      <td><div class="row-actions">
        <button class="btn icon sm ghost" data-edit="${u.id}" title="Edit">${App.icon('edit')}</button>
        ${u.id!=APP.user.id?`<button class="btn icon sm danger" data-del="${u.id}" title="Delete">${App.icon('trash')}</button>`:''}
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
          <option value="member" ${u?.role!=='admin'?'selected':''}>Member (add &amp; view)</option>
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

  App.register('buildings', loadBuildings);
  App.register('users', loadUsers);
  App.register('settings', loadSettings);
})();

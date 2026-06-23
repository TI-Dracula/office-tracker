/* Brochures view — provider document library (Spintly, TATA, ACT). No pricing. */
(() => {
  const PROVIDERS = ['Spintly', 'TATA', 'ACT'];   // suggestions; field is free text
  let brochures = [];
  let inited = false;
  let debounce;

  function init() {
    if (inited) return; inited = true;
    const add = document.getElementById('addBrochureBtn');
    if (add) add.onclick = () => openForm();        // absent for view-only users
    document.getElementById('brSearch').oninput = () => { clearTimeout(debounce); debounce = setTimeout(render, 200); };
    document.getElementById('brProvider').onchange = render;
  }

  async function load() {
    init();
    try { brochures = (await App.api('brochures_list')).brochures; }
    catch (e) { document.getElementById('brList').innerHTML = `<div class="panel empty">${App.esc(e.message)}</div>`; return; }

    const providers = [...new Set([...PROVIDERS, ...brochures.map(b => b.provider)])];
    const sel = document.getElementById('brProvider'); const cur = sel.value;
    sel.innerHTML = '<option value="">All providers</option>' +
      providers.map(p => `<option value="${App.esc(p)}">${App.esc(p)}</option>`).join('');
    sel.value = cur;
    render();
  }

  function render() {
    const q = document.getElementById('brSearch').value.trim().toLowerCase();
    const prov = document.getElementById('brProvider').value;
    const list = brochures.filter(b =>
      (!prov || b.provider === prov) &&
      (!q || (b.title + ' ' + b.provider + ' ' + (b.notes || '')).toLowerCase().includes(q)));

    const wrap = document.getElementById('brList');
    if (!list.length) {
      wrap.innerHTML = `<div class="panel empty"><div class="big">📚</div>No brochures yet.</div>`;
      return;
    }

    // group by provider
    const groups = {};
    list.forEach(b => (groups[b.provider] ??= []).push(b));

    wrap.innerHTML = Object.keys(groups).sort().map(p => `
      <div class="broch-group">
        <div class="broch-head"><span class="provider-chip">${App.esc(p)}</span>
          <span class="muted tiny">${groups[p].length} document${groups[p].length > 1 ? 's' : ''}</span></div>
        <div class="cards">
          ${groups[p].map(b => `
            <div class="pcard brochure">
              <div class="top">
                <div class="fi big-ic">${App.fileIcon(b.original_name)}</div>
                <div style="flex:1">
                  <div class="nm">${App.esc(b.title)}</div>
                  ${b.notes ? `<div class="cl">${App.esc(b.notes)}</div>` : ''}
                  ${b.size ? `<div class="tiny muted mt">${App.fileSize(b.size)}</div>` : ''}
                </div>
              </div>
              <div class="where" style="justify-content:space-between">
                ${b.has_file ? `<a class="btn sm ghost" href="api.php?action=brochure_download&id=${b.id}" target="_blank">👁 View</a>` : '<span class="muted tiny">No file</span>'}
                <span class="row-actions" style="opacity:1">
                  ${b.can_edit ? `<button class="btn icon sm ghost" title="Edit" data-edit="${b.id}">✎</button>
                  <button class="btn icon sm danger" title="Delete" data-del="${b.id}">🗑</button>` : ''}
                </span>
              </div>
            </div>`).join('')}
        </div>
      </div>`).join('');

    wrap.querySelectorAll('[data-edit]').forEach(b => b.onclick = () => openForm(+b.dataset.edit));
    wrap.querySelectorAll('[data-del]').forEach(b => b.onclick = () => del(+b.dataset.del));
  }

  async function openForm(id = 0) {
    let b = { provider: '', title: '', notes: '', original_name: null };
    if (id) { try { b = (await App.api('brochure_get', { params: { id } })).brochure; } catch (e) { return App.toast(e.message, 'err'); } }

    let chosenFile = null;
    const m = App.openModal({
      title: id ? 'Edit brochure' : 'Add brochure',
      body: `<div class="formgrid">
        <div class="field"><label class="lbl">Provider</label><input id="b_provider" list="provlist" value="${App.esc(b.provider || '')}" placeholder="Spintly / TATA / ACT"><datalist id="provlist">${PROVIDERS.map(p => `<option value="${App.esc(p)}">`).join('')}</datalist></div>
        <div class="field"><label class="lbl">Title</label><input id="b_title" value="${App.esc(b.title || '')}" placeholder="e.g. Spintly Access Control"></div>
        <div class="field full"><label class="lbl">Notes (optional)</label><textarea id="b_notes">${App.esc(b.notes || '')}</textarea></div>
        <div class="field full"><label class="lbl">File (PDF, image, doc — max 15 MB)</label>
          <div class="dropzone" id="b_drop">${b.original_name ? `Current: ${App.esc(b.original_name)} — click to replace` : '📎 Click or drop a file'}
            <input type="file" hidden id="b_file"></div>
        </div>
      </div>`,
      foot: `<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="b_save">${id ? 'Save changes' : 'Add brochure'}</button>`
    });

    const dz = m.querySelector('#b_drop'), input = m.querySelector('#b_file');
    dz.onclick = () => input.click();
    dz.ondragover = e => { e.preventDefault(); dz.classList.add('drag'); };
    dz.ondragleave = () => dz.classList.remove('drag');
    dz.ondrop = e => { e.preventDefault(); dz.classList.remove('drag'); if (e.dataTransfer.files[0]) { chosenFile = e.dataTransfer.files[0]; dz.textContent = '📄 ' + chosenFile.name; } };
    input.onchange = () => { if (input.files[0]) { chosenFile = input.files[0]; dz.textContent = '📄 ' + chosenFile.name; } };

    m.querySelector('#b_save').onclick = async () => {
      const provider = m.querySelector('#b_provider').value.trim();
      const title = m.querySelector('#b_title').value.trim();
      if (!provider) return App.toast('Please choose a provider.', 'err');
      if (!title) return App.toast('A title is required.', 'err');
      if (!id && !chosenFile) return App.toast('Please choose a file to upload.', 'err');

      const fd = new FormData();
      fd.append('id', id); fd.append('provider', provider); fd.append('title', title);
      fd.append('notes', m.querySelector('#b_notes').value);
      if (chosenFile) fd.append('file', chosenFile);

      const btn = m.querySelector('#b_save'); btn.disabled = true; btn.textContent = 'Saving…';
      try {
        await App.uploadFile('brochure_save', fd);
        App.toast(id ? 'Brochure updated' : 'Brochure added', 'ok');
        App.closeModal(); load();
      } catch (e) { App.toast(e.message, 'err'); btn.disabled = false; btn.textContent = id ? 'Save changes' : 'Add brochure'; }
    };
  }

  async function del(id) {
    if (!await App.confirmDialog('Delete this brochure and its file? This cannot be undone.')) return;
    try { await App.api('brochure_delete', { method: 'POST', body: { id } }); App.toast('Brochure deleted', 'ok'); load(); }
    catch (e) { App.toast(e.message, 'err'); }
  }

  App.register('brochures', load);
})();

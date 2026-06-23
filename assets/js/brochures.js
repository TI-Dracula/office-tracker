/* Brochures — vendor reference docs (no pricing). Members upload; everyone views. */
(() => {
  const KNOWN = ['Spintly', 'TATA', 'ACT'];
  let items = [];

  async function load() {
    const box = document.getElementById('brochuresContent');
    if (!box) return;
    box.innerHTML = '<div class="spin"></div>';
    const addBtn = document.getElementById('addBrochureBtn');
    if (addBtn) addBtn.onclick = () => openForm();
    try { items = (await App.api('brochures_list')).brochures; }
    catch (e) { box.innerHTML = `<div class="panel empty">${App.esc(e.message)}</div>`; return; }
    render();
  }

  function render() {
    const box = document.getElementById('brochuresContent');
    const canEdit = APP.canWrite;
    const byVendor = {};
    items.forEach(b => { (byVendor[b.vendor] ??= []).push(b); });
    const vendors = [...KNOWN, ...Object.keys(byVendor).filter(v => !KNOWN.includes(v)).sort()];

    box.innerHTML = vendors.map(v => {
      const list = byVendor[v] || [];
      const body = list.length
        ? `<div class="files mt">${list.map(b => brochureRow(b, canEdit)).join('')}</div>`
        : '<div class="tiny muted mt">No brochures yet.</div>';
      return `<div class="panel panel-pad" style="margin-bottom:18px">
        <div class="card-title">${App.esc(v)}<span class="pill">${list.length} ${list.length === 1 ? 'file' : 'files'}</span></div>
        ${body}
      </div>`;
    }).join('');

    box.querySelectorAll('[data-del-broc]').forEach(b => b.onclick = async () => {
      if (!await App.confirmDialog('Delete this brochure?')) return;
      try { await App.api('brochure_delete', { method: 'POST', body: { id: +b.dataset.delBroc } }); App.toast('Brochure deleted', 'ok'); load(); }
      catch (e) { App.toast(e.message, 'err'); }
    });
  }

  function brochureRow(b, canEdit) {
    const label = b.title || b.original_name;
    return `<div class="fileitem">
      <div class="fi">${App.fileIcon(b.original_name)}</div>
      <div class="fn"><b>${App.esc(label)}</b><span>${App.esc(b.original_name)} · ${App.fileSize(b.size)}</span></div>
      <a class="btn sm ghost" href="api.php?action=brochure_download&id=${b.id}" target="_blank">View</a>
      ${canEdit ? `<button class="btn sm danger" data-del-broc="${b.id}" title="Delete">${App.icon('close')}</button>` : ''}
    </div>`;
  }

  function openForm() {
    const m = App.openModal({
      title: 'Add brochure',
      body: `<div class="formgrid">
        <div class="field"><label class="lbl">Vendor</label><input id="b_vendor" placeholder="Pick a vendor or type a new one"></div>
        <div class="field"><label class="lbl">Title (optional)</label><input id="b_title" placeholder="e.g. Spintly Access Control 2026"></div>
        <div class="field full"><label class="lbl">Brochure file (PDF, image, doc — max 15 MB)</label>
          <div class="dropzone" id="b_drop">${App.icon('clip')} Click or drop the brochure file<input type="file" hidden id="b_file"></div>
          <div id="b_status" class="tiny muted mt"></div></div>
      </div>`,
      foot: `<button class="btn ghost" data-close>Close</button>`
    });
    App.combobox(m.querySelector('#b_vendor'), ['Spintly', 'TATA', 'ACT']);

    const drop = m.querySelector('#b_drop'), input = m.querySelector('#b_file'), status = m.querySelector('#b_status');
    drop.onclick = () => input.click();
    drop.ondragover = e => { e.preventDefault(); drop.classList.add('drag'); };
    drop.ondragleave = () => drop.classList.remove('drag');
    drop.ondrop = e => { e.preventDefault(); drop.classList.remove('drag'); if (e.dataTransfer.files[0]) upload(e.dataTransfer.files[0]); };
    input.onchange = () => { if (input.files[0]) upload(input.files[0]); };

    async function upload(file) {
      const vendor = m.querySelector('#b_vendor').value.trim();
      if (!vendor) return App.toast('Pick a vendor first.', 'err');
      status.textContent = 'Uploading ' + file.name + '…';
      const fd = new FormData();
      fd.append('file', file);
      fd.append('vendor', vendor);
      fd.append('title', m.querySelector('#b_title').value.trim());
      try { await App.uploadFile('brochure_upload', fd); App.toast('Brochure added', 'ok'); App.closeModal(); load(); }
      catch (e) { status.textContent = ''; App.toast(e.message, 'err'); }
    }
  }

  App.register('brochures', load);
})();

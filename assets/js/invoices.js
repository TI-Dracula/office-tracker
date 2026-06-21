/* Invoices view */
(() => {
  const state = { sort: 'invoice_date', dir: 'desc', page: 1 };
  let vendors = [];
  let inited = false;
  const CATEGORIES = ['Rent','Utilities','Maintenance','Supplies','Furniture','IT/Software','Services','Travel','Marketing','Other'];

  function filters() {
    return {
      q:        document.getElementById('invSearch').value.trim(),
      vendor_id:document.getElementById('invVendor').value,
      status:   document.getElementById('invStatus').value,
      date_from:document.getElementById('invFrom').value,
      date_to:  document.getElementById('invTo').value,
    };
  }

  let debounce;
  function init() {
    if (inited) return; inited = true;
    document.getElementById('addInvoiceBtn').onclick = () => openForm();
    document.getElementById('invSearch').oninput = () => { clearTimeout(debounce); debounce = setTimeout(() => { state.page = 1; refresh(); }, 300); };
    ['invVendor','invStatus','invFrom','invTo'].forEach(id =>
      document.getElementById(id).onchange = () => { state.page = 1; refresh(); });
    document.getElementById('invClear').onclick = () => {
      ['invSearch','invVendor','invStatus','invFrom','invTo'].forEach(id => document.getElementById(id).value = '');
      state.page = 1; refresh();
    };
    document.getElementById('invExport').onclick = () => {
      const f = filters(); let url = 'api.php?action=invoices_export';
      for (const [k,v] of Object.entries(f)) if (v) url += '&' + k + '=' + encodeURIComponent(v);
      window.location = url;
    };
    document.querySelectorAll('#invTable thead th.sortable').forEach(th => {
      th.onclick = () => {
        const s = th.dataset.sort;
        if (state.sort === s) state.dir = state.dir === 'asc' ? 'desc' : 'asc';
        else { state.sort = s; state.dir = s === 'amount' || s === 'invoice_date' ? 'desc' : 'asc'; }
        refresh();
      };
    });
  }

  async function load() {
    init();
    try {
      const v = await App.api('vendors_list'); vendors = v.vendors;
      const sel = document.getElementById('invVendor'); const cur = sel.value;
      sel.innerHTML = '<option value="">All vendors</option>' +
        vendors.map(x => `<option value="${x.id}">${App.esc(x.name)}</option>`).join('');
      sel.value = cur;
    } catch (_) {}
    refresh();
  }

  async function refresh() {
    const body = document.getElementById('invBody');
    body.innerHTML = `<tr><td colspan="8"><div class="spin"></div></td></tr>`;
    let d;
    try { d = await App.api('invoices_list', { params: { ...filters(), sort: state.sort, dir: state.dir, page: state.page } }); }
    catch (e) { body.innerHTML = `<tr><td colspan="8" class="empty">${App.esc(e.message)}</td></tr>`; return; }

    // header arrows
    document.querySelectorAll('#invTable thead th.sortable').forEach(th => {
      const on = th.dataset.sort === state.sort;
      th.classList.toggle('sorted', on);
      th.querySelector('.arr').textContent = on ? (state.dir === 'asc' ? '▲' : '▼') : '';
    });

    document.getElementById('invSummary').innerHTML =
      `<div class="it"><b>${d.total}</b><span>Invoices (filtered)</span></div>
       <div class="it"><b>${App.money(d.sum)}</b><span>Total value</span></div>
       <div class="it"><b>${d.total ? App.money(d.sum / d.total) : App.money(0)}</b><span>Average</span></div>`;

    if (!d.invoices.length) {
      body.innerHTML = `<tr><td colspan="8"><div class="empty">No invoices match. <span class="dot-link" id="emptyAdd">Add one →</span></div></td></tr>`;
      document.getElementById('emptyAdd').onclick = () => openForm();
    } else {
      body.innerHTML = d.invoices.map(r => {
        const fileCell = r.file_count > 0
          ? `<a class="btn sm ghost" href="api.php?action=file_download&type=invoice&id=${r.first_file_id}" target="_blank">${App.icon('view')} View${r.file_count>1?` (${r.file_count})`:''}</a>`
          : '<span class="muted">—</span>';
        const actions = r.can_edit
          ? `<button class="btn icon sm ghost" title="Edit" data-edit="${r.id}">${App.icon('edit')}</button>
             <button class="btn icon sm danger" title="Delete" data-del="${r.id}">${App.icon('trash')}</button>` : '';
        return `<tr>
          <td class="nowrap">${App.fmtDate(r.invoice_date)}</td>
          <td>${App.esc(r.vendor_name || '—')}</td>
          <td class="muted">${App.esc(r.invoice_number || '—')}</td>
          <td class="num">${App.money(r.amount)}</td>
          <td>${r.category ? App.esc(r.category) : '<span class="muted">—</span>'}</td>
          <td>${App.badge(r.status)}</td>
          <td>${fileCell}</td>
          <td><div class="row-actions">${actions}</div></td>
        </tr>`;
      }).join('');
      body.querySelectorAll('[data-edit]').forEach(b => b.onclick = () => openForm(+b.dataset.edit));
      body.querySelectorAll('[data-del]').forEach(b => b.onclick = () => del(+b.dataset.del));
    }

    // pager
    const pg = document.getElementById('invPager');
    if (d.pages > 1) {
      let btns = '';
      for (let i = 1; i <= d.pages; i++)
        btns += `<button class="btn sm ${i===d.page?'primary':''}" data-pg="${i}">${i}</button>`;
      pg.innerHTML = `<div class="pc">Page ${d.page} of ${d.pages}</div><div class="pgs">${btns}</div>`;
      pg.querySelectorAll('[data-pg]').forEach(b => b.onclick = () => { state.page = +b.dataset.pg; refresh(); });
    } else pg.innerHTML = '';
  }

  /* ---------- Add / edit form ---------- */
  async function openForm(id = 0) {
    let inv = { status: 'submitted', currency: APP.currency, files: [] };
    if (id) { try { inv = (await App.api('invoice_get', { params: { id } })).invoice; } catch (e) { return App.toast(e.message, 'err'); } }

    const today = new Date().toISOString().slice(0, 10);
    const statusSel = ['draft','submitted','approved','paid','rejected']
      .map(s => `<option value="${s}" ${inv.status===s?'selected':''}>${App.STATUS_LABEL[s]}</option>`).join('');

    const filesHtml = id ? renderFormFiles(inv.files) : '<p class="tiny muted">Save the invoice first, then you can attach the file.</p>';

    const m = App.openModal({
      title: id ? 'Edit invoice' : 'Add invoice',
      wide: true,
      body: `
        <div class="formgrid">
          <div class="field"><label class="lbl">Invoice date</label><input id="f_date" type="date" value="${inv.invoice_date||today}"></div>
          <div class="field"><label class="lbl">Vendor</label><input id="f_vendor" value="${App.esc(inv.vendor_name||'')}" placeholder="Pick a vendor or type a new one"></div>
          <div class="field"><label class="lbl">Invoice #</label><input id="f_num" value="${App.esc(inv.invoice_number||'')}"></div>
          <div class="field"><label class="lbl">Amount (${App.esc(APP.currency)})</label><input id="f_amount" type="number" step="0.01" min="0" value="${inv.amount||''}"></div>
          <div class="field"><label class="lbl">Category</label><input id="f_cat" value="${App.esc(inv.category||'')}" placeholder="Pick or type a category"></div>
          <div class="field"><label class="lbl">Status</label><select id="f_status">${statusSel}</select></div>
          <div class="field"><label class="lbl">Submitted to finance on</label><input id="f_sub" type="date" value="${inv.submitted_date||''}"></div>
          <div class="field full"><label class="lbl">Notes</label><textarea id="f_notes">${App.esc(inv.notes||'')}</textarea></div>
          <div class="field full"><label class="lbl">Attached file (invoice scan / PDF)</label><div id="f_files">${filesHtml}</div></div>
        </div>`,
      foot: `<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="f_save">${id?'Save changes':'Add invoice'}</button>`
    });

    App.combobox(m.querySelector('#f_vendor'), vendors.map(v => v.name));
    App.combobox(m.querySelector('#f_cat'), CATEGORIES);

    if (id) wireFileArea(m, 'invoice', id, () => refreshFiles(m, 'invoice', id));

    m.querySelector('#f_save').onclick = async () => {
      const payload = {
        id, invoice_date: m.querySelector('#f_date').value, vendor: m.querySelector('#f_vendor').value,
        invoice_number: m.querySelector('#f_num').value, amount: m.querySelector('#f_amount').value,
        category: m.querySelector('#f_cat').value, status: m.querySelector('#f_status').value,
        submitted_date: m.querySelector('#f_sub').value, notes: m.querySelector('#f_notes').value,
        currency: APP.currency
      };
      const btn = m.querySelector('#f_save'); btn.disabled = true; btn.textContent = 'Saving…';
      try {
        const r = await App.api('invoice_save', { method: 'POST', body: payload });
        if (r.duplicate_warning) App.toast('Heads up: another invoice has the same vendor & amount.', 'info');
        App.toast(id ? 'Invoice updated' : 'Invoice added', 'ok');
        if (!id) { App.closeModal(); await load(); openForm(r.id); }   // reopen so they can attach the file
        else { App.closeModal(); refresh(); }
      } catch (e) { App.toast(e.message, 'err'); btn.disabled = false; btn.textContent = id?'Save changes':'Add invoice'; }
    };
  }

  function renderFormFiles(files) {
    const list = (files && files.length)
      ? `<div class="files mt">${files.map(f => fileRow(f, 'invoice')).join('')}</div>` : '';
    return list + `<div class="dropzone mt" data-drop>${App.icon('clip')} Click or drop a file to attach (PDF, image, doc — max 15 MB)
      <input type="file" hidden data-fileinput></div>`;
  }
  function fileRow(f, type) {
    return `<div class="fileitem" data-file="${f.id}">
      <div class="fi">${App.fileIcon(f.original_name)}</div>
      <div class="fn"><b>${App.esc(f.original_name)}</b><span>${App.fileSize(f.size)}</span></div>
      <a class="btn sm ghost" href="api.php?action=file_download&type=${type}&id=${f.id}" target="_blank">View</a>
      <button class="btn sm danger" data-rmfile="${f.id}" data-type="${type}">${App.icon('close')}</button>
    </div>`;
  }

  function wireFileArea(root, type, targetId, after) {
    const dz = root.querySelector('[data-drop]'); if (!dz) return;
    const input = root.querySelector('[data-fileinput]');
    dz.onclick = () => input.click();
    dz.ondragover = e => { e.preventDefault(); dz.classList.add('drag'); };
    dz.ondragleave = () => dz.classList.remove('drag');
    dz.ondrop = e => { e.preventDefault(); dz.classList.remove('drag'); if (e.dataTransfer.files[0]) upload(e.dataTransfer.files[0]); };
    input.onchange = () => { if (input.files[0]) upload(input.files[0]); };
    async function upload(file) {
      const fd = new FormData(); fd.append('file', file); fd.append('target', type); fd.append('target_id', targetId);
      const docType = root.querySelector('#f_doctype'); if (docType) fd.append('doc_type', docType.value);
      dz.textContent = 'Uploading ' + file.name + '…';
      try { await App.uploadFile('file_upload', fd); App.toast('File attached', 'ok'); after(); }
      catch (e) { App.toast(e.message, 'err'); dz.textContent = 'Click or drop a file to attach'; }
    }
    root.querySelectorAll('[data-rmfile]').forEach(b => b.onclick = async () => {
      if (!await App.confirmDialog('Remove this file?', { okText: 'Remove' })) return;
      try { await App.api('file_delete', { method: 'POST', body: { type: b.dataset.type, id: +b.dataset.rmfile } }); after(); }
      catch (e) { App.toast(e.message, 'err'); }
    });
  }

  async function refreshFiles(root, type, id) {
    try {
      const d = type === 'invoice' ? await App.api('invoice_get', { params: { id } }) : await App.api('project_get', { params: { id } });
      const files = (d.invoice || d.project).files;
      root.querySelector('#f_files').innerHTML = renderFormFiles(files);
      wireFileArea(root, type, id, () => refreshFiles(root, type, id));
    } catch (e) { App.toast(e.message, 'err'); }
  }

  async function del(id) {
    if (!await App.confirmDialog('Delete this invoice and its attached file? This cannot be undone.')) return;
    try { await App.api('invoice_delete', { method: 'POST', body: { id } }); App.toast('Invoice deleted', 'ok'); refresh(); }
    catch (e) { App.toast(e.message, 'err'); }
  }

  App.register('invoices', load);
  // expose shared file helpers for projects module
  App._files = { wireFileArea, fileRow };
})();

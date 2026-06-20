<?php
require __DIR__ . '/lib/bootstrap.php';

$user = current_user();
$appName = setting_get('app_name', cfg('app.name', 'IBC Office Tracker'));
$cur = currency_symbol();

/* Reusable inline SVGs — premium line icons, no emojis */
$svgBuilding = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21V6l7-3 7 3v15"/><path d="M9 21v-5h4v5"/><path d="M8 8h.01M14 8h.01M8 12h.01M14 12h.01"/></svg>';
$svgPlus     = '<svg class="ic-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';
$svgDownload = '<svg class="ic-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="M7 11l5 5 5-5"/><path d="M5 21h14"/></svg>';
$svgLogout   = '<svg class="ic-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/></svg>';

/* ---------------- Not logged in: show login ---------------- */
if (!$user):
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($appName) ?> · Sign in</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,500;0,9..144,600;1,9..144,400&family=IBM+Plex+Mono:wght@400;500&family=Libre+Franklin:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="stylesheet" href="assets/css/app.css">
<script>try{if(localStorage.getItem('ibc-theme')!=='dark')document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>
</head><body>
<div class="login-wrap">
  <form class="login panel" id="loginForm">
    <div class="logo-lg"><?= $svgBuilding ?></div>
    <h1><?= e($appName) ?></h1>
    <p class="s">Sign in to continue</p>
    <div class="err" id="loginErr"></div>
    <div class="field"><label class="lbl">Username</label><input name="username" autofocus required></div>
    <div class="field"><label class="lbl">Password</label><input name="password" type="password" required></div>
    <button class="btn primary mt" style="width:100%" type="submit">Sign in →</button>
  </form>
</div>
<script>
const f=document.getElementById('loginForm'), err=document.getElementById('loginErr');
f.addEventListener('submit',async e=>{
  e.preventDefault(); err.style.display='none';
  const btn=f.querySelector('button'); btn.disabled=true; btn.textContent='Signing in…';
  try{
    const r=await fetch('api.php?action=login',{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({username:f.username.value,password:f.password.value})});
    const d=await r.json();
    if(d.ok){location.href='index.php';}
    else{err.textContent=d.error||'Login failed';err.style.display='block';btn.disabled=false;btn.textContent='Sign in →';}
  }catch(_){err.textContent='Network error. Try again.';err.style.display='block';btn.disabled=false;btn.textContent='Sign in →';}
});
</script>
</body></html>
<?php exit; endif; /* ---------------- Logged in: app shell ---------------- */ ?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($appName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,500;0,9..144,600;1,9..144,400&family=IBM+Plex+Mono:wght@400;500&family=Libre+Franklin:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="stylesheet" href="assets/css/app.css">
<script>try{if(localStorage.getItem('ibc-theme')!=='dark')document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>
</head><body>
<script>
window.APP = {
  user: <?= json_encode(public_user($user), JSON_UNESCAPED_UNICODE) ?>,
  isAdmin: <?= is_admin() ? 'true' : 'false' ?>,
  csrf: <?= json_encode(csrf_token()) ?>,
  currency: <?= json_encode($cur) ?>,
  appName: <?= json_encode($appName) ?>
};
</script>

<div class="app">
  <header class="topbar">
    <div class="brand"><div class="logo"><?= $svgBuilding ?></div><div><?= e($appName) ?><small>OFFICE OPERATIONS</small></div></div>
    <nav class="nav" id="nav">
      <a data-view="dashboard" class="active">Dashboard</a>
      <a data-view="invoices">Invoices</a>
      <a data-view="projects">Projects</a>
      <a data-view="pricing">IT Pricing</a>
      <?php if (is_admin()): ?>
      <a data-view="users">Users</a>
      <a data-view="settings">Settings</a>
      <?php endif; ?>
    </nav>
    <div class="spacer"></div>
    <div class="usermenu">
      <div class="meta right"><b><?= e($user['name']) ?></b><br><span><?= e(ucfirst($user['role'])) ?></span></div>
      <div class="avatar" id="profileBtn" title="My account" style="cursor:pointer"><?= e(strtoupper(substr($user['name'],0,1))) ?></div>
      <button class="btn ghost sm" id="themeToggle" title="Toggle light / dark theme"></button>
      <button class="btn ghost sm" id="logoutBtn" title="Sign out"><?= $svgLogout ?></button>
    </div>
  </header>

  <main class="main">
    <!-- DASHBOARD -->
    <section class="view active" id="view-dashboard">
      <div class="page-head"><div><h1>Dashboard</h1><div class="sub">Your invoices and projects at a glance</div></div></div>
      <div id="dashContent"><div class="spin"></div></div>
    </section>

    <!-- INVOICES -->
    <section class="view" id="view-invoices">
      <div class="page-head">
        <div><h1>Invoices</h1><div class="sub">Track everything submitted to finance</div></div>
        <button class="btn primary" id="addInvoiceBtn"><?= $svgPlus ?> Add invoice</button>
      </div>
      <div class="toolbar">
        <div class="search">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/></svg>
          <input id="invSearch" placeholder="Search vendor, invoice #, notes…">
        </div>
        <div class="filters">
          <select id="invVendor"><option value="">All vendors</option></select>
          <select id="invStatus">
            <option value="">All statuses</option>
            <option value="draft">Draft</option><option value="submitted">Submitted</option>
            <option value="approved">Approved</option><option value="paid">Paid</option><option value="rejected">Rejected</option>
          </select>
          <input id="invFrom" type="date" title="From date">
          <input id="invTo" type="date" title="To date">
          <button class="btn sm" id="invClear">Clear</button>
          <button class="btn sm" id="invExport"><?= $svgDownload ?> CSV</button>
        </div>
      </div>
      <div class="summary panel" id="invSummary"></div>
      <div class="panel"><div class="tablewrap"><table id="invTable">
        <thead><tr>
          <th class="sortable" data-sort="invoice_date">Date <span class="arr"></span></th>
          <th class="sortable" data-sort="vendor">Vendor <span class="arr"></span></th>
          <th class="sortable" data-sort="invoice_number">Invoice # <span class="arr"></span></th>
          <th class="sortable num" data-sort="amount">Amount <span class="arr"></span></th>
          <th class="sortable" data-sort="category">Category <span class="arr"></span></th>
          <th class="sortable" data-sort="status">Status <span class="arr"></span></th>
          <th>File</th><th></th>
        </tr></thead>
        <tbody id="invBody"></tbody>
      </table></div></div>
      <div class="pager" id="invPager"></div>
    </section>

    <!-- PROJECTS -->
    <section class="view" id="view-projects">
      <div class="page-head">
        <div><h1>Projects</h1><div class="sub">New fit-outs across DD · 1-OAR · GE · KP</div></div>
        <button class="btn primary" id="addProjectBtn"><?= $svgPlus ?> Add project</button>
      </div>
      <div class="subtabs" id="projTabs">
        <button data-tab="buildings" class="active">Building view</button>
        <button data-tab="cards">Open projects</button>
        <button data-tab="table">Table</button>
      </div>
      <div class="toolbar">
        <div class="search">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/></svg>
          <input id="prjSearch" placeholder="Search project, client, tower…">
        </div>
        <div class="filters">
          <select id="prjLoc"><option value="">All locations</option></select>
          <select id="prjStatus">
            <option value="">All statuses</option>
            <option value="open">Open</option><option value="in_progress">In progress</option>
            <option value="on_hold">On hold</option><option value="completed">Completed</option>
          </select>
          <label class="flex tiny" style="gap:6px;color:var(--mut)"><input type="checkbox" id="prjOpenOnly" style="width:auto"> Open only</label>
        </div>
      </div>
      <div id="prjBuildings" class="buildings"></div>
      <div id="prjCards" class="cards hidden"></div>
      <div id="prjTableWrap" class="panel hidden"><div class="tablewrap"><table>
        <thead><tr>
          <th class="sortable" data-sort="name">Project <span class="arr"></span></th>
          <th class="sortable" data-sort="location">Location <span class="arr"></span></th>
          <th>Tower / Floor</th>
          <th class="sortable" data-sort="handover_date">Handover <span class="arr"></span></th>
          <th class="sortable" data-sort="status">Status <span class="arr"></span></th>
          <th>Docs</th><th></th>
        </tr></thead>
        <tbody id="prjBody"></tbody>
      </table></div></div>
    </section>

    <!-- IT PRICING -->
    <section class="view" id="view-pricing">
      <div class="page-head"><div><h1>IT Pricing</h1><div class="sub">Internet &amp; access-control rate cards · vendor + 18% GST + 30% MOSS convenience</div></div></div>
      <div id="pricingContent"><div class="spin"></div></div>
    </section>

    <?php if (is_admin()): ?>
    <!-- USERS (admin) -->
    <section class="view" id="view-users">
      <div class="page-head"><div><h1>Users</h1><div class="sub">Who can access this tool</div></div>
        <button class="btn primary" id="addUserBtn"><?= $svgPlus ?> Add user</button></div>
      <div class="panel"><div class="tablewrap"><table>
        <thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th></th></tr></thead>
        <tbody id="usersBody"></tbody>
      </table></div></div>
    </section>

    <!-- SETTINGS (admin) -->
    <section class="view" id="view-settings">
      <div class="page-head"><div><h1>Settings</h1><div class="sub">General preferences</div></div></div>
      <div class="panel panel-pad" style="max-width:520px">
        <div class="formgrid">
          <div class="field full"><label class="lbl">App name</label><input id="setAppName"></div>
          <div class="field"><label class="lbl">Currency symbol</label><input id="setCurrency"></div>
        </div>
        <div class="mt2"><button class="btn primary" id="saveSettings">Save settings</button></div>
        <p class="tiny muted mt2">Auto-extraction of invoices is currently <b>off</b> (manual entry). It can be switched on later in <code>config.php</code> if you ever add an AI provider API key — no code changes needed.</p>
      </div>
    </section>
    <?php endif; ?>
  </main>
</div>

<!-- containers populated by JS -->
<div class="drawer-backdrop" id="drawerBackdrop"></div>
<div class="drawer" id="drawer"></div>
<div class="overlay" id="overlay"></div>
<div class="toasts" id="toasts"></div>

<script src="assets/js/app.js"></script>
<script src="assets/js/dashboard.js"></script>
<script src="assets/js/invoices.js"></script>
<script src="assets/js/projects.js"></script>
<script src="assets/js/pricing.js"></script>
<?php if (is_admin()): ?><script src="assets/js/admin.js"></script><?php endif; ?>
<script>App.start();</script>
<script>
/* Theme toggle (moon / sun SVG) */
(function(){
  var b=document.getElementById('themeToggle'); if(!b) return;
  var MOON='<svg class="ic-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8Z"/></svg>';
  var SUN='<svg class="ic-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5.6 5.6 4.2 4.2M19.8 19.8l-1.4-1.4M18.4 5.6l1.4-1.4M4.2 19.8l1.4-1.4"/></svg>';
  function sync(){ b.innerHTML = document.documentElement.getAttribute('data-theme')==='light' ? SUN : MOON; }
  sync();
  b.onclick=function(){
    var light = document.documentElement.getAttribute('data-theme')==='light';
    if(light){ document.documentElement.removeAttribute('data-theme'); try{localStorage.setItem('ibc-theme','dark');}catch(e){} }
    else     { document.documentElement.setAttribute('data-theme','light'); try{localStorage.setItem('ibc-theme','light');}catch(e){} }
    sync();
  };
})();
/* Profile · change own password */
(function(){
  var p=document.getElementById('profileBtn'); if(!p) return;
  p.onclick=function(){
    var m=App.openModal({
      title:'My account',
      body:'<div class="formgrid">'
        +'<div class="field full"><label class="lbl">Signed in as</label><input value="'+App.esc(APP.user.name)+' ('+App.esc(APP.user.username)+')" disabled></div>'
        +'<div class="field full"><label class="lbl">Current password</label><input id="cp_cur" type="password" autocomplete="current-password"></div>'
        +'<div class="field"><label class="lbl">New password</label><input id="cp_new" type="password" autocomplete="new-password"></div>'
        +'<div class="field"><label class="lbl">Confirm new password</label><input id="cp_conf" type="password" autocomplete="new-password"></div>'
        +'</div>',
      foot:'<button class="btn ghost" data-close>Close</button><button class="btn primary" id="cp_save">Change password</button>'
    });
    m.querySelector('#cp_save').onclick=async function(){
      var cur=m.querySelector('#cp_cur').value, nw=m.querySelector('#cp_new').value, cf=m.querySelector('#cp_conf').value;
      if(nw.length<6){ App.toast('New password must be at least 6 characters.','err'); return; }
      if(nw!==cf){ App.toast('New passwords do not match.','err'); return; }
      var sb=m.querySelector('#cp_save'); sb.disabled=true; sb.textContent='Saving…';
      try{ await App.api('change_password',{method:'POST',body:{current:cur,'new':nw}}); App.toast('Password changed.','ok'); App.closeModal(); }
      catch(e){ App.toast(e.message,'err'); sb.disabled=false; sb.textContent='Change password'; }
    };
  };
})();
</script>
</body></html>

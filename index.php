<?php
require __DIR__ . '/lib/bootstrap.php';

$user = current_user();
$appName = setting_get('app_name', cfg('app.name', 'IBC Office Tracker'));
$cur = currency_symbol();

/* ---------------- Not logged in: show login ---------------- */
if (!$user):
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($appName) ?> · Sign in</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
</head><body>
<div class="login-wrap">
  <form class="login panel" id="loginForm">
    <div class="logo-lg">🏢</div>
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
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
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
    <div class="brand"><div class="logo">🏢</div><div><?= e($appName) ?><small>OFFICE OPERATIONS</small></div></div>
    <nav class="nav" id="nav">
      <a data-view="dashboard" class="active">📊 <span class="txt">Dashboard</span></a>
      <a data-view="invoices">🧾 <span class="txt">Invoices</span></a>
      <a data-view="projects">🏗️ <span class="txt">Projects</span></a>
      <?php if (is_admin()): ?>
      <a data-view="buildings">🏙️ <span class="txt">Buildings</span></a>
      <a data-view="users">👥 <span class="txt">Users</span></a>
      <a data-view="settings">⚙️ <span class="txt">Settings</span></a>
      <?php endif; ?>
    </nav>
    <div class="spacer"></div>
    <div class="usermenu">
      <div class="meta right"><b><?= e($user['name']) ?></b><br><span><?= e(ucfirst($user['role'])) ?></span></div>
      <div class="avatar"><?= e(strtoupper(substr($user['name'],0,1))) ?></div>
      <button class="btn ghost sm" id="logoutBtn" title="Sign out">⎋</button>
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
        <button class="btn primary" id="addInvoiceBtn">＋ Add invoice</button>
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
          <button class="btn sm" id="invExport">⬇ CSV</button>
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
        <button class="btn primary" id="addProjectBtn">＋ Add project</button>
      </div>
      <div class="subtabs" id="projTabs">
        <button data-tab="buildings" class="active">🏙️ Building view</button>
        <button data-tab="cards">🗂️ Open projects</button>
        <button data-tab="table">📋 Table</button>
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

    <?php if (is_admin()): ?>
    <!-- BUILDINGS (admin) -->
    <section class="view" id="view-buildings">
      <div class="page-head"><div><h1>Buildings</h1><div class="sub">Set towers, floors &amp; colours so the visual matches reality</div></div></div>
      <div id="bldEditor" class="buildings"><div class="spin"></div></div>
    </section>

    <!-- USERS (admin) -->
    <section class="view" id="view-users">
      <div class="page-head"><div><h1>Users</h1><div class="sub">Who can access this tool</div></div>
        <button class="btn primary" id="addUserBtn">＋ Add user</button></div>
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
        <p class="tiny muted mt2">Auto-extraction of invoices is currently <b>off</b> (manual entry). It can be switched on later in <code>config.php</code> if you ever add an Anthropic API key — no code changes needed.</p>
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
<?php if (is_admin()): ?><script src="assets/js/admin.js"></script><?php endif; ?>
<script>App.start();</script>
</body></html>

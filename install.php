<?php
/**
 * One-time setup wizard.
 * Open this in your browser after uploading the files. It will:
 *   1. test your MySQL credentials,
 *   2. create config.php,
 *   3. build all database tables + seed the four buildings,
 *   4. create your first admin user.
 * Delete this file afterwards (it refuses to run once setup is complete).
 */
declare(strict_types=1);
define('ROOT', __DIR__);
$CONFIG_FILE = ROOT . '/config.php';
$LOCK_FILE   = ROOT . '/.installed';

$alreadyInstalled = file_exists($CONFIG_FILE) && file_exists($LOCK_FILE);
$errors = [];
$done = false;

function post(string $k, string $d = ''): string { return trim((string)($_POST[$k] ?? $d)); }

if (!$alreadyInstalled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $db_host = post('db_host', 'localhost');
    $db_name = post('db_name');
    $db_user = post('db_user');
    $db_pass = (string)($_POST['db_pass'] ?? '');
    $app_name = post('app_name', 'IBC Office Tracker');
    $currency = post('currency', '₹') ?: '₹';
    $a_name = post('admin_name');
    $a_user = post('admin_user');
    $a_pass = (string)($_POST['admin_pass'] ?? '');

    if ($db_name === '' || $db_user === '') $errors[] = 'Database name and user are required.';
    if ($a_name === '' || $a_user === '')   $errors[] = 'Admin name and username are required.';
    if (strlen($a_pass) < 6)                $errors[] = 'Admin password must be at least 6 characters.';

    $pdo = null;
    if (!$errors) {
        try {
            $pdo = new PDO(
                "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
                $db_user, $db_pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            $errors[] = 'Could not connect to the database. Double-check the name, user, password, and that the database exists in cPanel. (' . $e->getMessage() . ')';
        }
    }

    if (!$errors && $pdo) {
        try {
            // Run schema
            $sql = file_get_contents(ROOT . '/sql/schema.sql');
            $sql = preg_replace('/^\s*--.*$/m', '', $sql); // strip comment lines
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                $pdo->exec($stmt);
            }

            // Create admin if no users yet
            $hasUser = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if ($hasUser === 0) {
                $ins = $pdo->prepare('INSERT INTO users (name, username, role, active, password_hash) VALUES (?,?,?,1,?)');
                $ins->execute([$a_name, $a_user, 'admin', password_hash($a_pass, PASSWORD_DEFAULT)]);
            }

            // Save app settings
            $set = $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
            $set->execute(['app_name', $app_name]);
            $set->execute(['currency_symbol', $currency]);

            // Write config.php
            $config = [
                'db' => [
                    'host' => $db_host, 'name' => $db_name, 'user' => $db_user,
                    'pass' => $db_pass, 'charset' => 'utf8mb4',
                ],
                'app' => [
                    'name' => $app_name, 'currency' => 'INR', 'currency_symbol' => $currency,
                    'timezone' => 'Asia/Kolkata', 'max_upload_mb' => 15,
                ],
                'ai' => ['enabled' => false, 'api_key' => '', 'model' => 'claude-haiku-4-5-20251001'],
                'secret' => bin2hex(random_bytes(32)),
            ];
            file_put_contents($CONFIG_FILE, "<?php\nreturn " . var_export($config, true) . ";\n");
            file_put_contents($LOCK_FILE, date('c'));
            $done = true;
        } catch (Throwable $e) {
            $errors[] = 'Setup failed while building the database: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup · Office Tracker</title>
<style>
  :root{--bg:#0b0f1a;--panel:#141a2b;--panel2:#1b2336;--line:#2a3450;--ink:#e8edf7;--mut:#8b97b5;--accent:#6ea8fe;--ok:#34d399;--bad:#fb7185;}
  *{box-sizing:border-box}
  body{margin:0;font:15px/1.5 system-ui,Segoe UI,Roboto,sans-serif;background:
      radial-gradient(1200px 600px at 80% -10%, rgba(110,168,254,.18), transparent),
      radial-gradient(900px 500px at -10% 110%, rgba(167,139,250,.16), transparent), var(--bg);
      color:var(--ink);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:30px}
  .card{width:100%;max-width:560px;background:linear-gradient(180deg,var(--panel),var(--panel2));
      border:1px solid var(--line);border-radius:18px;padding:30px;box-shadow:0 30px 80px rgba(0,0,0,.5)}
  h1{margin:0 0 4px;font-size:22px;letter-spacing:.2px}
  p.sub{margin:0 0 22px;color:var(--mut)}
  label{display:block;font-size:12.5px;color:var(--mut);margin:14px 0 6px;text-transform:uppercase;letter-spacing:.5px}
  input{width:100%;padding:11px 13px;background:#0d1322;border:1px solid var(--line);border-radius:10px;color:var(--ink);font-size:15px}
  input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(110,168,254,.2)}
  .row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  .sec{margin-top:22px;padding-top:14px;border-top:1px dashed var(--line);font-size:12px;letter-spacing:1px;color:var(--accent);text-transform:uppercase}
  button{margin-top:24px;width:100%;padding:13px;border:0;border-radius:12px;font-size:16px;font-weight:600;
      background:linear-gradient(180deg,var(--accent),#4b86e6);color:#06122b;cursor:pointer}
  button:hover{filter:brightness(1.07)}
  .err{background:rgba(251,113,133,.12);border:1px solid rgba(251,113,133,.4);color:#ffd7dd;padding:12px 14px;border-radius:10px;margin-bottom:14px;font-size:14px}
  .ok{background:rgba(52,211,153,.12);border:1px solid rgba(52,211,153,.4);color:#c7f9e7;padding:16px;border-radius:12px}
  a.btn{display:inline-block;margin-top:14px;background:var(--ok);color:#04201a;padding:11px 18px;border-radius:10px;text-decoration:none;font-weight:600}
  code{background:#0d1322;padding:2px 7px;border-radius:6px;border:1px solid var(--line)}
  .hint{font-size:12px;color:var(--mut);margin-top:4px}
</style>
</head>
<body>
<div class="card">
  <h1>🏢 Office Tracker — Setup</h1>
  <p class="sub">One-time install. Takes about a minute.</p>

  <?php if ($alreadyInstalled): ?>
    <div class="ok">
      <strong>Already installed ✓</strong><br>
      For security, please <strong>delete <code>install.php</code></strong> from the server.
      <br><a class="btn" href="index.php">Open the app →</a>
    </div>
  <?php elseif ($done): ?>
    <div class="ok">
      <strong>All set! 🎉</strong><br>
      Your database is ready and your admin account is created.
      <br><br>⚠️ <strong>Important:</strong> delete <code>install.php</code> now so nobody can re-run setup.
      <br><a class="btn" href="index.php">Open the app →</a>
    </div>
  <?php else: ?>
    <?php foreach ($errors as $er): ?><div class="err"><?= htmlspecialchars($er) ?></div><?php endforeach; ?>
    <form method="post" autocomplete="off">
      <div class="sec">Database (from cPanel → MySQL® Databases)</div>
      <label>DB Host</label>
      <input name="db_host" value="<?= htmlspecialchars(post('db_host','localhost')) ?>">
      <div class="hint">On InMotion shared hosting this is almost always <code>localhost</code>.</div>
      <label>Database name</label>
      <input name="db_name" value="<?= htmlspecialchars(post('db_name')) ?>" placeholder="usr1234_tracker" required>
      <div class="row">
        <div><label>Database user</label><input name="db_user" value="<?= htmlspecialchars(post('db_user')) ?>" placeholder="usr1234_app" required></div>
        <div><label>Database password</label><input name="db_pass" type="password"></div>
      </div>

      <div class="sec">Your admin account</div>
      <div class="row">
        <div><label>Your name</label><input name="admin_name" value="<?= htmlspecialchars(post('admin_name')) ?>" required></div>
        <div><label>Username</label><input name="admin_user" value="<?= htmlspecialchars(post('admin_user')) ?>" required></div>
      </div>
      <label>Password (min 6 chars)</label>
      <input name="admin_pass" type="password" required>

      <div class="sec">App</div>
      <div class="row">
        <div><label>App name</label><input name="app_name" value="<?= htmlspecialchars(post('app_name','IBC Office Tracker')) ?>"></div>
        <div><label>Currency symbol</label><input name="currency" value="<?= htmlspecialchars(post('currency','₹')) ?>"></div>
      </div>

      <button type="submit">Install →</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>

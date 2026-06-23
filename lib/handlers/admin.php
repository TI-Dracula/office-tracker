<?php
/** Dashboard, user management, and settings. */

function h_dashboard(): void {
    $db = db();
    $fin = is_member();   // view-only users get NO finance data — not even over the wire

    // Invoice summary (members/admins only)
    $invTot = ['n' => 0, 'total' => 0]; $byStatus = []; $monthTotal = 0.0; $trend = []; $topVendors = [];
    if ($fin) {
        $invTot = $db->query('SELECT COUNT(*) n, COALESCE(SUM(amount),0) total FROM invoices')->fetch();
        $byStatus = $db->query('SELECT status, COUNT(*) n, COALESCE(SUM(amount),0) total FROM invoices GROUP BY status')->fetchAll();

        $mt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE invoice_date >= ?");
        $mt->execute([date('Y-m-01')]);
        $monthTotal = (float)$mt->fetchColumn();

        $trend = $db->query(
            "SELECT DATE_FORMAT(invoice_date, '%Y-%m') ym, COALESCE(SUM(amount),0) total, COUNT(*) n
             FROM invoices
             WHERE invoice_date >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 5 MONTH)
             GROUP BY ym ORDER BY ym"
        )->fetchAll();

        $topVendors = $db->query(
            "SELECT v.name, COUNT(*) n, COALESCE(SUM(i.amount),0) total
             FROM invoices i JOIN vendors v ON v.id = i.vendor_id
             GROUP BY v.id ORDER BY total DESC LIMIT 5"
        )->fetchAll();
    }

    // Projects
    $prjOpen = (int)$db->query("SELECT COUNT(*) FROM projects WHERE status IN ('open','in_progress','on_hold')")->fetchColumn();
    $prjAll  = (int)$db->query('SELECT COUNT(*) FROM projects')->fetchColumn();

    // Upcoming handovers (next 30 days, active)
    $up = $db->query(
        "SELECT p.id, p.name, p.handover_date, l.code location_code, l.color location_color, p.tower, p.floor
         FROM projects p LEFT JOIN locations l ON l.id = p.location_id
         WHERE p.handover_date IS NOT NULL
           AND p.status IN ('open','in_progress','on_hold')
           AND p.handover_date >= CURDATE()
           AND p.handover_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
         ORDER BY p.handover_date ASC LIMIT 8"
    )->fetchAll();
    foreach ($up as &$u) $u['days_left'] = days_until($u['handover_date']);

    // Projects per location (active)
    $perLoc = $db->query(
        "SELECT l.code, l.color,
                SUM(CASE WHEN p.status IN ('open','in_progress','on_hold') THEN 1 ELSE 0 END) active,
                COUNT(p.id) total
         FROM locations l LEFT JOIN projects p ON p.location_id = l.id
         GROUP BY l.id ORDER BY l.sort_order"
    )->fetchAll();

    json_out([
        'ok' => true,
        'finance' => $fin,
        'invoices' => [
            'count'        => (int)$invTot['n'],
            'total'        => (float)$invTot['total'],
            'month_total'  => $monthTotal,
            'by_status'    => $byStatus,
            'trend'        => $trend,
            'top_vendors'  => $topVendors,
        ],
        'projects' => [
            'open'    => $prjOpen,
            'total'   => $prjAll,
            'upcoming'=> $up,
            'per_loc' => $perLoc,
        ],
        'currency' => currency_symbol(),
    ]);
}

/** -------- Users (admin) -------- */
function h_users_list(): void {
    $rows = db()->query('SELECT id, name, username, email, role, active, created_at FROM users ORDER BY name')->fetchAll();
    json_out(['ok' => true, 'users' => $rows]);
}

function h_user_save(): void {
    $in = json_input();
    $id = (int)($in['id'] ?? 0);
    $name = clean($in['name'] ?? null);
    $username = clean($in['username'] ?? null);
    $role = ($in['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
    $email = clean($in['email'] ?? null);
    $password = (string)($in['password'] ?? '');
    $active = !empty($in['active']) ? 1 : 0;

    if (!$name || !$username) json_error('Name and username are required.', 422);
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('Please enter a valid email address.', 422);

    if ($id > 0) {
        // Don't let the last admin demote/deactivate themselves into lockout
        if ((int)current_user()['id'] === $id && ($role !== 'admin' || !$active)) {
            $admins = (int)db()->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn();
            if ($admins <= 1) json_error('You are the only active admin — you cannot remove your own admin access.', 422);
        }
        $sql = 'UPDATE users SET name=?, username=?, email=?, role=?, active=?';
        $params = [$name, $username, $email, $role, $active];
        if ($password !== '') {
            if (strlen($password) < 6) json_error('Password must be at least 6 characters.', 422);
            $sql .= ', password_hash=?';
            $params[] = password_hash($password, PASSWORD_DEFAULT);
        }
        $sql .= ' WHERE id=?';
        $params[] = $id;
        try {
            db()->prepare($sql)->execute($params);
        } catch (PDOException $e) {
            json_error('That username is already taken.', 422);
        }
        log_activity('user_update', "User #$id");
    } else {
        if ($password === '') {
            if (!$email) json_error('Set a password, or add an email address so we can send the login invite.', 422);
            $password = generate_temp_password();
        } elseif (strlen($password) < 6) {
            json_error('Password must be at least 6 characters.', 422);
        }
        try {
            $st = db()->prepare('INSERT INTO users (name, username, email, role, active, password_hash) VALUES (?,?,?,?,?,?)');
            $st->execute([$name, $username, $email, $role, $active, password_hash($password, PASSWORD_DEFAULT)]);
        } catch (PDOException $e) {
            json_error('That username is already taken.', 422);
        }
        $id = (int)db()->lastInsertId();
        log_activity('user_create', "User #$id");
        if ($email) send_welcome_email($email, $name, $username, $password);
    }
    json_out(['ok' => true, 'id' => $id]);
}

function h_user_delete(): void {
    $in = json_input();
    $id = (int)($in['id'] ?? 0);
    if ((int)current_user()['id'] === $id) json_error('You cannot delete your own account.', 422);
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    log_activity('user_delete', "User #$id");
    json_out(['ok' => true]);
}

/** -------- Settings -------- */
function h_settings_get(): void {
    json_out([
        'ok' => true,
        'settings' => [
            'app_name'        => setting_get('app_name', cfg('app.name', 'MOSS Operations')),
            'currency_symbol' => currency_symbol(),
        ],
    ]);
}

function h_settings_save(): void {
    $in = json_input();
    if (isset($in['app_name']))        setting_set('app_name', clean($in['app_name']) ?? 'MOSS Operations');
    if (isset($in['currency_symbol'])) setting_set('currency_symbol', clean($in['currency_symbol']) ?? '₹');
    log_activity('settings_update', '');
    json_out(['ok' => true]);
}

/**
 * Email a newly-created user a branded invitation with their login details.
 * Delivery: SendGrid if a key is set in config.php (mail.sendgrid_key), else PHP mail()
 * (works on cPanel / InMotion). Never throws — account creation must not depend on email.
 */
function send_welcome_email(string $to, string $name, string $username, string $password): void {
    try {
        $app    = (string) setting_get('app_name', cfg('app.name', 'MOSS Operations'));
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base   = $scheme . '://' . $host . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        $loginUrl = $base . '/index.php';
        $subject  = "You've been invited to $app";

        $text =
            "Hi $name,\r\n\r\n" .
            "An account has been created for you on $app.\r\n\r\n" .
            "Sign in: $loginUrl\r\nUsername: $username\r\nTemporary password: $password\r\n\r\n" .
            "Please change your password after signing in (account menu, top-right).\r\n\r\n— $app";

        $eApp = e($app); $eName = e($name); $eUser = e($username); $ePass = e($password); $eUrl = e($loginUrl);
        $html = <<<HTML
<!doctype html><html><body style="margin:0;padding:0;background:#0A1410;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0A1410;padding:32px 12px;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;"><tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;background:#0F1D17;border:1px solid rgba(199,161,90,.34);border-radius:14px;">
<tr><td style="padding:30px 38px 0;font-family:Georgia,'Times New Roman',serif;font-size:19px;font-weight:bold;color:#F3EEDD;"><span style="color:#C7A15A;">&#9612;</span>&nbsp; $eApp</td></tr>
<tr><td style="padding:22px 38px 0;">
<div style="font-family:Georgia,'Times New Roman',serif;font-size:27px;color:#F3EEDD;line-height:1.2;">You've been invited</div>
<div style="font-size:15px;color:#C7CBBE;line-height:1.6;margin-top:12px;">Hi $eName, an account has been created for you on <b style="color:#F3EEDD;">$eApp</b>. Use the details below to sign in &mdash; then change your password.</div></td></tr>
<tr><td style="padding:20px 38px 4px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#14271E;border:1px solid rgba(199,161,90,.34);border-radius:10px;"><tr><td style="padding:18px 22px;">
<div style="font-size:11px;letter-spacing:1.2px;text-transform:uppercase;color:#8C9588;">Username</div>
<div style="font-family:'Courier New',monospace;font-size:16px;color:#F3EEDD;margin:5px 0 16px;">$eUser</div>
<div style="font-size:11px;letter-spacing:1.2px;text-transform:uppercase;color:#8C9588;">Temporary password</div>
<div style="font-family:'Courier New',monospace;font-size:16px;color:#F3EEDD;margin:5px 0 0;">$ePass</div>
</td></tr></table></td></tr>
<tr><td style="padding:18px 38px 6px;"><a href="$eUrl" style="display:inline-block;background:#C7A15A;color:#0A1410;font-weight:bold;font-size:15px;text-decoration:none;padding:13px 30px;border-radius:8px;">Sign in &rarr;</a></td></tr>
<tr><td style="padding:8px 38px 28px;font-size:12.5px;color:#8C9588;line-height:1.6;">For your security, please change this temporary password from your account menu right after you sign in.</td></tr>
<tr><td style="padding:16px 38px;border-top:1px solid rgba(243,238,221,.08);font-size:12px;color:#8C9588;">$eApp &middot; Office operations</td></tr>
</table></td></tr></table></body></html>
HTML;

        app_send_mail($to, $subject, $html, $text);
    } catch (Throwable $e) {
        // swallow — account creation must succeed regardless of mail delivery
    }
}

/** Send an email via SendGrid (if mail.sendgrid_key is set) or PHP mail(). Never throws. */
function app_send_mail(string $to, string $subject, string $html, string $text): void {
    try {
        $app   = (string) setting_get('app_name', cfg('app.name', 'MOSS Operations'));
        $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dom   = preg_replace('/^www\./', '', explode(':', $host)[0]) ?: 'localhost';
        $from  = (string) (cfg('mail.from') ?: 'no-reply@' . $dom);
        $fname = (string) (cfg('mail.from_name') ?: $app);
        $key   = (string) cfg('mail.sendgrid_key', '');

        if ($key !== '' && function_exists('curl_init')) {
            $payload = json_encode([
                'personalizations' => [['to' => [['email' => $to]]]],
                'from'    => ['email' => $from, 'name' => $fname],
                'subject' => $subject,
                'content' => [
                    ['type' => 'text/plain', 'value' => $text],
                    ['type' => 'text/html',  'value' => $html],
                ],
            ]);
            $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
            ]);
            curl_exec($ch);
            curl_close($ch);
            return;
        }

        $boundary = 'bd' . md5(uniqid('', true));
        $headers  = "From: $fname <$from>\r\nReply-To: $from\r\n" .
                    "Message-ID: <" . md5(uniqid('', true)) . "@$dom>\r\nMIME-Version: 1.0\r\n" .
                    "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\nX-Mailer: PHP";
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$text\r\n\r\n" .
                "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n$html\r\n\r\n--$boundary--";
        // -f sets the envelope sender so SPF aligns with your domain (big deliverability win on cPanel).
        @mail($to, $subject, $body, $headers, '-f' . $from);
    } catch (Throwable $e) {
        // swallow
    }
}

/** Generate a readable random temporary password (no ambiguous 0/O/1/l characters). */
function generate_temp_password(int $len = 12): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $len; $i++) $out .= $alphabet[random_int(0, $max)];
    return $out;
}

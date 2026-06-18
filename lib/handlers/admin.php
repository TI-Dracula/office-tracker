<?php
/** Dashboard, user management, and settings. */

function h_dashboard(): void {
    $db = db();

    // Invoice summary
    $invTot = $db->query('SELECT COUNT(*) n, COALESCE(SUM(amount),0) total FROM invoices')->fetch();
    $byStatus = $db->query('SELECT status, COUNT(*) n, COALESCE(SUM(amount),0) total FROM invoices GROUP BY status')->fetchAll();

    // This-month total
    $mt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE invoice_date >= ?");
    $mt->execute([date('Y-m-01')]);
    $monthTotal = (float)$mt->fetchColumn();

    // Last 6 months trend
    $trend = $db->query(
        "SELECT DATE_FORMAT(invoice_date, '%Y-%m') ym, COALESCE(SUM(amount),0) total, COUNT(*) n
         FROM invoices
         WHERE invoice_date >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 5 MONTH)
         GROUP BY ym ORDER BY ym"
    )->fetchAll();

    // Top vendors
    $topVendors = $db->query(
        "SELECT v.name, COUNT(*) n, COALESCE(SUM(i.amount),0) total
         FROM invoices i JOIN vendors v ON v.id = i.vendor_id
         GROUP BY v.id ORDER BY total DESC LIMIT 5"
    )->fetchAll();

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
        if (strlen($password) < 6) json_error('Password must be at least 6 characters.', 422);
        try {
            $st = db()->prepare('INSERT INTO users (name, username, email, role, active, password_hash) VALUES (?,?,?,?,?,?)');
            $st->execute([$name, $username, $email, $role, $active, password_hash($password, PASSWORD_DEFAULT)]);
        } catch (PDOException $e) {
            json_error('That username is already taken.', 422);
        }
        $id = (int)db()->lastInsertId();
        log_activity('user_create', "User #$id");
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
            'app_name'        => setting_get('app_name', cfg('app.name', 'IBC Office Tracker')),
            'currency_symbol' => currency_symbol(),
        ],
    ]);
}

function h_settings_save(): void {
    $in = json_input();
    if (isset($in['app_name']))        setting_set('app_name', clean($in['app_name']) ?? 'IBC Office Tracker');
    if (isset($in['currency_symbol'])) setting_set('currency_symbol', clean($in['currency_symbol']) ?? '₹');
    log_activity('settings_update', '');
    json_out(['ok' => true]);
}

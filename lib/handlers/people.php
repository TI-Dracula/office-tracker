<?php
/** People directory (asset assignees) + Microsoft 365 sync. */

require_once __DIR__ . '/../m365.php';

function h_people_list(): void {
    $rows = db()->query(
        "SELECT p.id, p.display_name, p.email, p.job_title, p.department, p.source,
                p.active, p.m365_id, p.synced_at,
                (SELECT COUNT(*) FROM assets a WHERE a.assigned_person_id = p.id) AS asset_count
         FROM people p
         ORDER BY p.active DESC, p.display_name"
    )->fetchAll();
    foreach ($rows as &$r) {
        $r['id']          = (int) $r['id'];
        $r['active']      = (int) $r['active'];
        $r['asset_count'] = (int) $r['asset_count'];
        $r['is_m365']     = $r['m365_id'] !== null;
        unset($r['m365_id']);
    }
    json_out(['ok' => true, 'people' => $rows]);
}

function h_person_save(): void {
    $in   = json_input();
    $id   = (int) ($in['id'] ?? 0);
    $name = clean($in['display_name'] ?? null);
    if ($name === null) json_error('A name is required.', 422);

    $fields = [
        'display_name' => $name,
        'email'        => clean($in['email'] ?? null),
        'job_title'    => clean($in['job_title'] ?? null),
        'department'   => clean($in['department'] ?? null),
        'active'       => !empty($in['active']) ? 1 : 0,
    ];

    if ($id > 0) {
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
        db()->prepare("UPDATE people SET $set WHERE id = :id")->execute($fields + ['id' => $id]);
        log_activity('person_update', "Person #$id");
    } else {
        $fields['source'] = 'manual';
        $cols = implode(', ', array_keys($fields));
        $ph   = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
        db()->prepare("INSERT INTO people ($cols) VALUES ($ph)")->execute($fields);
        $id = (int) db()->lastInsertId();
        log_activity('person_create', "Person #$id");
    }
    json_out(['ok' => true, 'id' => $id]);
}

function h_person_delete(): void {
    $in = json_input();
    $id = (int) ($in['id'] ?? 0);
    // Assets referencing this person are detached automatically (FK ON DELETE SET NULL).
    db()->prepare('DELETE FROM people WHERE id = ?')->execute([$id]);
    log_activity('person_delete', "Person #$id");
    json_out(['ok' => true]);
}

/** Admin panel status: is M365 wired up, and when did we last sync. */
function h_m365_status(): void {
    $counts = db()->query(
        "SELECT
            COUNT(*) AS total,
            SUM(source = 'm365')   AS from_m365,
            SUM(source = 'manual') AS manual
         FROM people"
    )->fetch();
    json_out([
        'ok'        => true,
        'enabled'   => m365_enabled(),
        'last_sync' => setting_get('m365_last_sync', null),
        'counts'    => [
            'total'  => (int) $counts['total'],
            'm365'   => (int) $counts['from_m365'],
            'manual' => (int) $counts['manual'],
        ],
    ]);
}

/** Pull the directory from Microsoft 365 and upsert into `people`. */
function h_m365_sync(): void {
    if (!m365_enabled()) {
        json_error('Microsoft 365 is not configured yet. Add the m365 credentials in config.php first.', 422);
    }

    try {
        $users = m365_fetch_users();
    } catch (Throwable $e) {
        json_error($e->getMessage(), 502);
    }

    $db = db();
    $syncDisabled = (bool) cfg('m365.sync_disabled_users', false);

    $up = $db->prepare(
        "INSERT INTO people (m365_id, display_name, email, upn, job_title, department, source, active, synced_at)
         VALUES (:m365_id, :display_name, :email, :upn, :job_title, :department, 'm365', :active, NOW())
         ON DUPLICATE KEY UPDATE
            display_name = VALUES(display_name), email = VALUES(email), upn = VALUES(upn),
            job_title = VALUES(job_title), department = VALUES(department),
            source = 'm365', active = VALUES(active), synced_at = NOW()"
    );

    $seen = [];
    foreach ($users as $u) {
        $seen[] = $u['m365_id'];
        $up->execute([
            'm365_id'      => $u['m365_id'],
            'display_name' => $u['display_name'],
            'email'        => $u['email'],
            'upn'          => $u['upn'],
            'job_title'    => $u['job_title'],
            'department'   => $u['department'],
            'active'       => $syncDisabled ? (int) $u['active'] : 1,
        ]);
    }

    // Optionally deactivate M365 people who are no longer in the directory.
    if ($syncDisabled && $seen) {
        $ph = implode(',', array_fill(0, count($seen), '?'));
        $db->prepare("UPDATE people SET active = 0 WHERE source = 'm365' AND m365_id NOT IN ($ph)")
           ->execute($seen);
    }

    setting_set('m365_last_sync', date('c'));
    log_activity('m365_sync', count($users) . ' users');
    json_out(['ok' => true, 'synced' => count($users)]);
}

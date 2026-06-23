<?php
/** Asset management — equipment, assignment, and a durable history trail. */

const ASSET_STATUSES = ['in_use', 'in_stock', 'repair', 'retired'];

/** Append a row to the asset history. */
function asset_log(int $assetId, string $type, ?int $personId, ?string $detail, ?string $date): void {
    db()->prepare(
        'INSERT INTO asset_events (asset_id, event_type, person_id, detail, event_date, created_by)
         VALUES (?,?,?,?,?,?)'
    )->execute([$assetId, $type, $personId, $detail, $date, current_user()['id'] ?? null]);
}

function h_assets_list(): void {
    $g = $_GET;
    $where = [];
    $params = [];

    if ($q = clean($g['q'] ?? null)) {
        $where[] = '(a.name LIKE ? OR a.asset_tag LIKE ? OR a.serial_no LIKE ? OR a.category LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if (($s = clean($g['status'] ?? null)) && in_array($s, ASSET_STATUSES, true)) {
        $where[] = 'a.status = ?';
        $params[] = $s;
    }
    if ($cat = clean($g['category'] ?? null)) {
        $where[] = 'a.category = ?';
        $params[] = $cat;
    }
    $assigned = clean($g['assigned'] ?? null);
    if ($assigned === 'unassigned') {
        $where[] = 'a.assigned_person_id IS NULL';
    } elseif ($assigned !== null && ctype_digit($assigned)) {
        $where[] = 'a.assigned_person_id = ?';
        $params[] = (int) $assigned;
    }

    $sortMap = [
        'name'        => 'a.name',
        'category'    => 'a.category',
        'status'      => 'a.status',
        'assignee'    => 'pe.display_name',
        'assigned_on' => 'a.assigned_on',
        'created_at'  => 'a.created_at',
    ];
    $sortCol = $sortMap[$g['sort'] ?? 'name'] ?? 'a.name';
    $dir = strtolower($g['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "SELECT a.*, pe.display_name AS assignee_name, l.code AS location_code, l.color AS location_color
            FROM assets a
            LEFT JOIN people pe   ON pe.id = a.assigned_person_id
            LEFT JOIN locations l ON l.id = a.location_id
            $whereSql
            ORDER BY $sortCol $dir, a.id DESC";
    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['id']       = (int) $r['id'];
        $r['can_edit'] = can_edit_record($r['created_by']);
    }
    json_out(['ok' => true, 'assets' => $rows]);
}

function h_asset_get(): void {
    $id = (int) ($_GET['id'] ?? 0);
    $st = db()->prepare(
        "SELECT a.*, pe.display_name AS assignee_name, pe.email AS assignee_email,
                l.code AS location_code, l.name AS location_name, l.color AS location_color,
                u.name AS creator_name
         FROM assets a
         LEFT JOIN people pe   ON pe.id = a.assigned_person_id
         LEFT JOIN locations l ON l.id = a.location_id
         LEFT JOIN users u     ON u.id = a.created_by
         WHERE a.id = ?"
    );
    $st->execute([$id]);
    $a = $st->fetch();
    if (!$a) json_error('Asset not found.', 404);

    $ev = db()->prepare(
        "SELECT e.event_type, e.detail, e.event_date, e.created_at, p.display_name AS person_name, u.name AS by_name
         FROM asset_events e
         LEFT JOIN people p ON p.id = e.person_id
         LEFT JOIN users  u ON u.id = e.created_by
         WHERE e.asset_id = ?
         ORDER BY e.id DESC"
    );
    $ev->execute([$id]);
    $a['events']   = $ev->fetchAll();
    $a['can_edit'] = can_edit_record($a['created_by']);
    json_out(['ok' => true, 'asset' => $a]);
}

function h_asset_save(): void {
    $in = json_input();
    $id = (int) ($in['id'] ?? 0);

    $name = clean($in['name'] ?? null);
    if ($name === null) json_error('Asset name is required.', 422);

    $status = $in['status'] ?? 'in_stock';
    if (!in_array($status, ASSET_STATUSES, true)) $status = 'in_stock';

    $personId = ($pid = (int) clean($in['assigned_person_id'] ?? null)) > 0 ? $pid : null;
    // An assignment date only makes sense when someone is assigned.
    $assignedOn = $personId !== null ? clean($in['assigned_on'] ?? null) : null;

    $fields = [
        'asset_tag'          => clean($in['asset_tag'] ?? null),
        'name'               => $name,
        'category'           => clean($in['category'] ?? null),
        'serial_no'          => clean($in['serial_no'] ?? null),
        'status'             => $status,
        'assigned_person_id' => $personId,
        'assigned_on'        => $assignedOn,
        'location_id'        => ($lid = (int) clean($in['location_id'] ?? null)) > 0 ? $lid : null,
        'notes'              => clean($in['notes'] ?? null),
    ];

    if ($id > 0) {
        $cur = db()->prepare('SELECT created_by, status, assigned_person_id, assigned_on FROM assets WHERE id = ?');
        $cur->execute([$id]);
        $old = $cur->fetch();
        if (!$old) json_error('Asset not found.', 404);
        if (!can_edit_record($old['created_by'])) json_error('You can only edit assets you added.', 403);

        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
        db()->prepare("UPDATE assets SET $set WHERE id = :id")->execute($fields + ['id' => $id]);

        // History: record assignment changes and status changes.
        $oldPerson = $old['assigned_person_id'] !== null ? (int) $old['assigned_person_id'] : null;
        if ($oldPerson !== $personId) {
            if ($personId === null) {
                asset_log($id, 'returned', $oldPerson, 'Returned / unassigned', $assignedOn ?: date('Y-m-d'));
            } else {
                asset_log($id, 'assigned', $personId, person_label($personId), $assignedOn ?: date('Y-m-d'));
            }
        }
        if ($old['status'] !== $status) {
            asset_log($id, 'status_change', $personId, status_label($old['status']) . ' → ' . status_label($status), date('Y-m-d'));
        }
        log_activity('asset_update', "Asset #$id");
    } else {
        $fields['created_by'] = current_user()['id'];
        $cols = implode(', ', array_keys($fields));
        $ph   = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
        db()->prepare("INSERT INTO assets ($cols) VALUES ($ph)")->execute($fields);
        $id = (int) db()->lastInsertId();

        asset_log($id, 'created', null, 'Added to inventory', date('Y-m-d'));
        if ($personId !== null) {
            asset_log($id, 'assigned', $personId, person_label($personId), $assignedOn ?: date('Y-m-d'));
        }
        log_activity('asset_create', "Asset #$id");
    }
    json_out(['ok' => true, 'id' => $id]);
}

function h_asset_delete(): void {
    $in = json_input();
    $id = (int) ($in['id'] ?? 0);
    $st = db()->prepare('SELECT created_by FROM assets WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) json_error('Asset not found.', 404);
    if (!can_edit_record($row['created_by'])) json_error('You can only delete assets you added.', 403);

    db()->prepare('DELETE FROM assets WHERE id = ?')->execute([$id]); // events cascade
    log_activity('asset_delete', "Asset #$id");
    json_out(['ok' => true]);
}

function h_assets_export(): void {
    $rows = db()->query(
        "SELECT a.asset_tag, a.name, a.category, a.serial_no, a.status,
                pe.display_name AS assignee, a.assigned_on, l.code AS location, a.notes
         FROM assets a
         LEFT JOIN people pe   ON pe.id = a.assigned_person_id
         LEFT JOIN locations l ON l.id = a.location_id
         ORDER BY a.name"
    );
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="assets_export.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Tag', 'Name', 'Category', 'Serial', 'Status', 'Assigned to', 'Assigned on', 'Location', 'Notes']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['asset_tag'], $r['name'], $r['category'], $r['serial_no'], $r['status'],
            $r['assignee'], $r['assigned_on'], $r['location'], $r['notes'],
        ]);
    }
    fclose($out);
    exit;
}

/** "Display Name" for an asset_events detail line. */
function person_label(int $personId): string {
    $st = db()->prepare('SELECT display_name FROM people WHERE id = ?');
    $st->execute([$personId]);
    $n = $st->fetchColumn();
    return $n !== false ? 'Assigned to ' . $n : 'Assigned';
}

function status_label(string $s): string {
    return ['in_use' => 'In use', 'in_stock' => 'In stock', 'repair' => 'Repair', 'retired' => 'Retired'][$s] ?? $s;
}

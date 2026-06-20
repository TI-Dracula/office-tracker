<?php
/** Project & location (building) endpoints. */

const PROJECT_STATUSES = ['open', 'in_progress', 'completed', 'on_hold'];
/** Statuses considered "active/occupied" for the building bars. */
const PROJECT_ACTIVE = ['open', 'in_progress', 'on_hold'];

function parse_towers(?string $csv): array {
    $csv = (string)$csv;
    $parts = array_values(array_filter(array_map('trim', explode(',', $csv)), fn($x) => $x !== ''));
    return $parts ?: ['A'];
}

function h_locations_list(): void {
    $rows = db()->query('SELECT * FROM locations ORDER BY sort_order, code')->fetchAll();
    foreach ($rows as &$r) {
        $r['id']         = (int)$r['id'];
        $r['floors']     = (int)$r['floors'];
        $r['towers_arr'] = parse_towers($r['towers']);
    }
    json_out(['ok' => true, 'locations' => $rows]);
}

function h_location_save(): void {
    $in = json_input();
    $id = (int)($in['id'] ?? 0);
    if ($id <= 0) json_error('Missing building id.', 422);

    $fields = [
        'name'     => clean($in['name'] ?? null) ?? 'Building',
        'maps_url' => clean($in['maps_url'] ?? null),
        'towers'   => implode(',', parse_towers($in['towers'] ?? 'A')),
        'floors'   => max(1, min(100, (int)($in['floors'] ?? 10))),
        'color'    => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($in['color'] ?? '')) ? $in['color'] : '#6ea8fe',
    ];
    $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
    $st = db()->prepare("UPDATE locations SET $set WHERE id = :id");
    $st->execute($fields + ['id' => $id]);
    log_activity('location_update', "Building #$id");
    json_out(['ok' => true]);
}

function h_projects_list(): void {
    $g = $_GET;
    $where = [];
    $params = [];

    if ($q = clean($g['q'] ?? null)) {
        $where[] = '(p.name LIKE ? OR p.client LIKE ? OR p.tower LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like);
    }
    if ($lid = clean($g['location_id'] ?? null)) {
        $where[] = 'p.location_id = ?';
        $params[] = (int)$lid;
    }
    if (($s = clean($g['status'] ?? null)) && in_array($s, PROJECT_STATUSES, true)) {
        $where[] = 'p.status = ?';
        $params[] = $s;
    }
    if (!empty($g['open_only'])) {
        $in = implode(',', array_fill(0, count(PROJECT_ACTIVE), '?'));
        $where[] = "p.status IN ($in)";
        array_push($params, ...PROJECT_ACTIVE);
    }

    $sortMap = [
        'handover_date' => 'p.handover_date',
        'name'          => 'p.name',
        'location'      => 'l.code',
        'floor'         => 'p.floor',
        'status'        => 'p.status',
        'created_at'    => 'p.created_at',
    ];
    $sortCol = $sortMap[$g['sort'] ?? 'handover_date'] ?? 'p.handover_date';
    $dir = strtolower($g['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "SELECT p.*, l.code AS location_code, l.name AS location_name, l.color AS location_color,
                   (SELECT COUNT(*) FROM project_files f WHERE f.project_id = p.id) AS file_count
            FROM projects p
            LEFT JOIN locations l ON l.id = p.location_id
            $whereSql
            ORDER BY ($sortCol IS NULL), $sortCol $dir, p.id DESC";
    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['id']        = (int)$r['id'];
        $r['floor']     = $r['floor'] !== null ? (int)$r['floor'] : null;
        $r['is_active'] = in_array($r['status'], PROJECT_ACTIVE, true);
        $r['can_edit']  = can_edit_record($r['created_by']);
        $r['days_left'] = $r['handover_date'] ? days_until($r['handover_date']) : null;
    }
    json_out(['ok' => true, 'projects' => $rows]);
}

/** Whole-number days from today to $date (negative if past). */
function days_until(string $date): ?int {
    $d = date_create($date);
    if (!$d) return null;
    $today = date_create('today');
    return (int)$today->diff($d)->format('%r%a');
}

function h_project_get(): void {
    $id = (int)($_GET['id'] ?? 0);
    $st = db()->prepare("SELECT p.*, l.code AS location_code, l.name AS location_name, l.color AS location_color,
                                l.maps_url AS location_maps, u.name AS creator_name
                         FROM projects p
                         LEFT JOIN locations l ON l.id = p.location_id
                         LEFT JOIN users u ON u.id = p.created_by
                         WHERE p.id = ?");
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) json_error('Project not found.', 404);

    $fs = db()->prepare('SELECT id, doc_type, original_name, mime, size, uploaded_at FROM project_files WHERE project_id = ? ORDER BY id');
    $fs->execute([$id]);
    $p['files'] = $fs->fetchAll();
    $p['days_left'] = $p['handover_date'] ? days_until($p['handover_date']) : null;
    $p['can_edit'] = can_edit_record($p['created_by']);
    json_out(['ok' => true, 'project' => $p]);
}

function h_project_save(): void {
    $in = json_input();
    $id = (int)($in['id'] ?? 0);

    $name = clean($in['name'] ?? null);
    if ($name === null) json_error('Project name is required.', 422);

    $status = $in['status'] ?? 'open';
    if (!in_array($status, PROJECT_STATUSES, true)) $status = 'open';

    $fields = [
        'name'          => $name,
        'location_id'   => ($lid = clean($in['location_id'] ?? null)) ? (int)$lid : null,
        'tower'         => clean($in['tower'] ?? null),
        'floor'         => ($f = clean($in['floor'] ?? null)) !== null ? (int)$f : null,
        'handover_date' => clean($in['handover_date'] ?? null),
        'status'        => $status,
        'area_sqft'     => ($a = clean($in['area_sqft'] ?? null)) !== null ? (int)$a : null,
        'client'        => clean($in['client'] ?? null),
        'notes'         => clean($in['notes'] ?? null),
        'lan_per_ws'    => ($v = clean($in['lan_per_ws'] ?? null)) !== null ? (int)$v : null,
        'wireless_ap'   => ($v = clean($in['wireless_ap'] ?? null)) !== null ? (int)$v : null,
        'meeting_tv'    => ($v = clean($in['meeting_tv'] ?? null)) !== null ? (int)$v : null,
        'meeting_table' => ($v = clean($in['meeting_table'] ?? null)) !== null ? (int)$v : null,
        'has_ll'        => !empty($in['has_ll']) ? 1 : 0,
        'll_primary'    => clean($in['ll_primary'] ?? null),
        'll_secondary'  => clean($in['ll_secondary'] ?? null),
        'spintly_push'  => ($v = clean($in['spintly_push'] ?? null)) !== null ? (int)$v : null,
        'spintly_pull'  => ($v = clean($in['spintly_pull'] ?? null)) !== null ? (int)$v : null,
        'spintly_gateway' => !empty($in['spintly_gateway']) ? 1 : 0,
    ];

    if ($id > 0) {
        $owner = db()->prepare('SELECT created_by FROM projects WHERE id = ?');
        $owner->execute([$id]);
        $row = $owner->fetch();
        if (!$row) json_error('Project not found.', 404);
        if (!can_edit_record($row['created_by'])) json_error('You can only edit projects you added.', 403);

        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
        $st = db()->prepare("UPDATE projects SET $set WHERE id = :id");
        $st->execute($fields + ['id' => $id]);
        log_activity('project_update', "Project #$id");
    } else {
        $fields['created_by'] = current_user()['id'];
        $cols = implode(', ', array_keys($fields));
        $ph = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
        $st = db()->prepare("INSERT INTO projects ($cols) VALUES ($ph)");
        $st->execute($fields);
        $id = (int)db()->lastInsertId();
        log_activity('project_create', "Project #$id");
    }
    json_out(['ok' => true, 'id' => $id]);
}

function h_project_delete(): void {
    $in = json_input();
    $id = (int)($in['id'] ?? 0);
    $st = db()->prepare('SELECT created_by FROM projects WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) json_error('Project not found.', 404);
    if (!can_edit_record($row['created_by'])) json_error('You can only delete projects you added.', 403);

    $fs = db()->prepare('SELECT stored_name FROM project_files WHERE project_id = ?');
    $fs->execute([$id]);
    foreach ($fs->fetchAll() as $f) {
        $p = uploads_dir() . '/' . $f['stored_name'];
        if (is_file($p)) @unlink($p);
    }
    db()->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
    log_activity('project_delete', "Project #$id");
    json_out(['ok' => true]);
}

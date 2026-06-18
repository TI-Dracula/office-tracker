<?php
/** Invoice endpoints. */

const INVOICE_STATUSES = ['draft', 'submitted', 'approved', 'paid', 'rejected'];

/** Find a vendor id by name, creating the vendor if needed. */
function vendor_id_for(?string $name): ?int {
    $name = clean($name);
    if ($name === null) return null;
    $st = db()->prepare('SELECT id FROM vendors WHERE name = ?');
    $st->execute([$name]);
    $id = $st->fetchColumn();
    if ($id) return (int)$id;
    $ins = db()->prepare('INSERT INTO vendors (name) VALUES (?)');
    $ins->execute([$name]);
    return (int)db()->lastInsertId();
}

function h_vendors_list(): void {
    $rows = db()->query('SELECT id, name FROM vendors ORDER BY name')->fetchAll();
    json_out(['ok' => true, 'vendors' => $rows]);
}

/** Build WHERE clause + params from request filters. */
function invoice_filters(array $g): array {
    $where = [];
    $params = [];

    if ($q = clean($g['q'] ?? null)) {
        $where[] = '(i.invoice_number LIKE ? OR i.notes LIKE ? OR v.name LIKE ? OR i.category LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if ($vid = clean($g['vendor_id'] ?? null)) {
        $where[] = 'i.vendor_id = ?';
        $params[] = (int)$vid;
    }
    if (($s = clean($g['status'] ?? null)) && in_array($s, INVOICE_STATUSES, true)) {
        $where[] = 'i.status = ?';
        $params[] = $s;
    }
    if ($cat = clean($g['category'] ?? null)) {
        $where[] = 'i.category = ?';
        $params[] = $cat;
    }
    if ($from = clean($g['date_from'] ?? null)) {
        $where[] = 'i.invoice_date >= ?';
        $params[] = $from;
    }
    if ($to = clean($g['date_to'] ?? null)) {
        $where[] = 'i.invoice_date <= ?';
        $params[] = $to;
    }
    return [$where ? ('WHERE ' . implode(' AND ', $where)) : '', $params];
}

function h_invoices_list(): void {
    $g = $_GET;
    [$whereSql, $params] = invoice_filters($g);

    // Sorting (whitelist only)
    $sortMap = [
        'invoice_date'   => 'i.invoice_date',
        'amount'         => 'i.amount',
        'vendor'         => 'v.name',
        'invoice_number' => 'i.invoice_number',
        'status'         => 'i.status',
        'category'       => 'i.category',
        'created_at'     => 'i.created_at',
    ];
    $sortKey = $g['sort'] ?? 'invoice_date';
    $sortCol = $sortMap[$sortKey] ?? 'i.invoice_date';
    $dir = strtolower($g['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

    // Pagination
    $page = max(1, (int)($g['page'] ?? 1));
    $perPage = (int)($g['per_page'] ?? 25);
    $perPage = max(5, min(200, $perPage));
    $offset = ($page - 1) * $perPage;

    $join = "FROM invoices i
             LEFT JOIN vendors v ON v.id = i.vendor_id
             LEFT JOIN users u   ON u.id = i.created_by";

    // Totals for the filtered set (for the summary strip)
    $totStmt = db()->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(i.amount),0) AS total $join $whereSql");
    $totStmt->execute($params);
    $tot = $totStmt->fetch();

    $sql = "SELECT i.*, v.name AS vendor_name,
                   (SELECT COUNT(*) FROM invoice_files f WHERE f.invoice_id = i.id) AS file_count,
                   (SELECT f.id FROM invoice_files f WHERE f.invoice_id = i.id ORDER BY f.id LIMIT 1) AS first_file_id,
                   u.name AS creator_name
            $join
            $whereSql
            ORDER BY $sortCol $dir, i.id DESC
            LIMIT $perPage OFFSET $offset";
    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    foreach ($rows as &$r) {
        $r['can_edit'] = can_edit_record($r['created_by']);
        $r['amount'] = (float)$r['amount'];
    }

    json_out([
        'ok'       => true,
        'invoices' => $rows,
        'total'    => (int)$tot['n'],
        'sum'      => (float)$tot['total'],
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => max(1, (int)ceil($tot['n'] / $perPage)),
    ]);
}

function h_invoice_get(): void {
    $id = (int)($_GET['id'] ?? 0);
    $st = db()->prepare('SELECT i.*, v.name AS vendor_name FROM invoices i LEFT JOIN vendors v ON v.id=i.vendor_id WHERE i.id = ?');
    $st->execute([$id]);
    $inv = $st->fetch();
    if (!$inv) json_error('Invoice not found.', 404);

    $fs = db()->prepare('SELECT id, original_name, mime, size FROM invoice_files WHERE invoice_id = ? ORDER BY id');
    $fs->execute([$id]);
    $inv['files'] = $fs->fetchAll();
    $inv['amount'] = (float)$inv['amount'];
    $inv['can_edit'] = can_edit_record($inv['created_by']);
    json_out(['ok' => true, 'invoice' => $inv]);
}

function h_invoice_save(): void {
    $in = json_input();
    $id = (int)($in['id'] ?? 0);

    $status = $in['status'] ?? 'submitted';
    if (!in_array($status, INVOICE_STATUSES, true)) $status = 'submitted';

    $amount = (float)($in['amount'] ?? 0);
    if ($amount < 0) json_error('Amount cannot be negative.', 422);

    $vendorId = vendor_id_for($in['vendor'] ?? null);

    $fields = [
        'invoice_date'   => clean($in['invoice_date'] ?? null),
        'vendor_id'      => $vendorId,
        'invoice_number' => clean($in['invoice_number'] ?? null),
        'amount'         => $amount,
        'currency'       => clean($in['currency'] ?? null) ?? cfg('app.currency', 'INR'),
        'category'       => clean($in['category'] ?? null),
        'status'         => $status,
        'submitted_date' => clean($in['submitted_date'] ?? null),
        'notes'          => clean($in['notes'] ?? null),
    ];

    if ($id > 0) {
        $owner = db()->prepare('SELECT created_by FROM invoices WHERE id = ?');
        $owner->execute([$id]);
        $row = $owner->fetch();
        if (!$row) json_error('Invoice not found.', 404);
        if (!can_edit_record($row['created_by'])) json_error('You can only edit invoices you added.', 403);

        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
        $st = db()->prepare("UPDATE invoices SET $set WHERE id = :id");
        $st->execute($fields + ['id' => $id]);
        log_activity('invoice_update', "Invoice #$id");
    } else {
        $fields['created_by'] = current_user()['id'];
        $cols = implode(', ', array_keys($fields));
        $ph = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
        $st = db()->prepare("INSERT INTO invoices ($cols) VALUES ($ph)");
        $st->execute($fields);
        $id = (int)db()->lastInsertId();
        log_activity('invoice_create', "Invoice #$id");
    }

    // Duplicate heads-up (same vendor + amount, different record)
    $dupWarn = false;
    if ($vendorId && $amount > 0) {
        $d = db()->prepare('SELECT COUNT(*) FROM invoices WHERE vendor_id = ? AND amount = ? AND id <> ?');
        $d->execute([$vendorId, $amount, $id]);
        $dupWarn = $d->fetchColumn() > 0;
    }

    json_out(['ok' => true, 'id' => $id, 'duplicate_warning' => $dupWarn]);
}

function h_invoice_delete(): void {
    $in = json_input();
    $id = (int)($in['id'] ?? 0);
    $st = db()->prepare('SELECT created_by FROM invoices WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) json_error('Invoice not found.', 404);
    if (!can_edit_record($row['created_by'])) json_error('You can only delete invoices you added.', 403);

    // Remove physical files first
    $fs = db()->prepare('SELECT stored_name FROM invoice_files WHERE invoice_id = ?');
    $fs->execute([$id]);
    foreach ($fs->fetchAll() as $f) {
        $p = uploads_dir() . '/' . $f['stored_name'];
        if (is_file($p)) @unlink($p);
    }
    db()->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
    log_activity('invoice_delete', "Invoice #$id");
    json_out(['ok' => true]);
}

function h_invoices_export(): void {
    $g = $_GET;
    [$whereSql, $params] = invoice_filters($g);
    $sql = "SELECT i.invoice_date, v.name AS vendor, i.invoice_number, i.amount, i.currency,
                   i.category, i.status, i.submitted_date, i.notes
            FROM invoices i LEFT JOIN vendors v ON v.id = i.vendor_id
            $whereSql ORDER BY i.invoice_date DESC, i.id DESC";
    $st = db()->prepare($sql);
    $st->execute($params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="invoices_export.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows ₹ correctly
    fputcsv($out, ['Date', 'Vendor', 'Invoice #', 'Amount', 'Currency', 'Category', 'Status', 'Submitted', 'Notes']);
    foreach ($st as $r) {
        fputcsv($out, [
            $r['invoice_date'], $r['vendor'], $r['invoice_number'], $r['amount'],
            $r['currency'], $r['category'], $r['status'], $r['submitted_date'], $r['notes'],
        ]);
    }
    fclose($out);
    exit;
}

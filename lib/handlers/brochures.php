<?php
/** Vendor brochures — reference documents (no pricing). Members upload; everyone (incl. viewers) can view. */

function h_brochures_list(): void {
    $rows = db()->query(
        'SELECT b.id, b.vendor, b.title, b.original_name, b.mime, b.size, b.uploaded_at, u.name AS uploader
         FROM brochures b LEFT JOIN users u ON u.id = b.uploaded_by
         ORDER BY b.vendor, b.id DESC'
    )->fetchAll();
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['size'] = (int)$r['size']; }
    json_out(['ok' => true, 'brochures' => $rows]);
}

function h_brochure_upload(): void {
    $vendor = clean($_POST['vendor'] ?? null);
    $title  = clean($_POST['title'] ?? null);
    if ($vendor === null) json_error('Pick a vendor.', 422);
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        json_error('No file received (or it was too large for the server).', 422);
    }

    $f = $_FILES['file'];
    $maxBytes = (int)cfg('app.max_upload_mb', 15) * 1024 * 1024;
    if ($f['size'] > $maxBytes) json_error('File is larger than the ' . cfg('app.max_upload_mb', 15) . ' MB limit.', 422);

    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXT, true)) json_error('That file type is not allowed.', 422);

    if (!is_dir(uploads_dir())) @mkdir(uploads_dir(), 0775, true);
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], uploads_dir() . '/' . $stored)) {
        json_error('Could not save the file. Check folder permissions on the server.', 500);
    }

    $st = db()->prepare('INSERT INTO brochures (vendor, title, original_name, stored_name, mime, size, uploaded_by) VALUES (?,?,?,?,?,?,?)');
    $st->execute([$vendor, $title, $f['name'], $stored, mime_for_ext($ext), $f['size'], current_user()['id']]);
    log_activity('brochure_upload', "$vendor: {$f['name']}");
    json_out(['ok' => true, 'id' => (int)db()->lastInsertId()]);
}

function h_brochure_download(): void {
    $id = (int)($_GET['id'] ?? 0);
    $st = db()->prepare('SELECT * FROM brochures WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) { http_response_code(404); echo 'Not found'; exit; }

    $path = uploads_dir() . '/' . basename($row['stored_name']);
    if (!is_file($path)) { http_response_code(404); echo 'File missing'; exit; }

    $ext  = strtolower(pathinfo($row['original_name'], PATHINFO_EXTENSION));
    $mime = $row['mime'] ?: mime_for_ext($ext);
    $forceDl = !empty($_GET['dl']) || !in_array($mime, INLINE_MIME, true);

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($forceDl ? 'attachment' : 'inline') . '; filename="' . rawurlencode($row['original_name']) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($path);
    exit;
}

function h_brochure_delete(): void {
    $in = json_input();
    $id = (int)($in['id'] ?? 0);
    $st = db()->prepare('SELECT stored_name FROM brochures WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) json_error('Brochure not found.', 404);

    $path = uploads_dir() . '/' . basename($row['stored_name']);
    if (is_file($path)) @unlink($path);
    db()->prepare('DELETE FROM brochures WHERE id = ?')->execute([$id]);
    log_activity('brochure_delete', "Brochure #$id");
    json_out(['ok' => true]);
}

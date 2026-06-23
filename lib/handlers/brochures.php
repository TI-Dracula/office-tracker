<?php
/**
 * Brochures — provider reference documents (Spintly, TATA, ACT, …). No pricing.
 * One uploaded file per brochure, stored outside the web root like other uploads.
 * Readable/downloadable by every logged-in role (including view-only).
 */

/** Validate + store an uploaded brochure file. Returns [stored, original, mime, size]. */
function brochure_store_upload(array $f): array {
    if ($f['error'] !== UPLOAD_ERR_OK) {
        json_error('No file received (or it was too large for the server).', 422);
    }
    $maxBytes = (int) cfg('app.max_upload_mb', 15) * 1024 * 1024;
    if ($f['size'] > $maxBytes) {
        json_error('File is larger than the ' . cfg('app.max_upload_mb', 15) . ' MB limit.', 422);
    }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXT, true)) json_error('That file type is not allowed.', 422);

    if (!is_dir(uploads_dir())) @mkdir(uploads_dir(), 0775, true);
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], uploads_dir() . '/' . $stored)) {
        json_error('Could not save the file. Check folder permissions on the server.', 500);
    }
    return [$stored, $f['name'], mime_for_ext($ext), (int) $f['size']];
}

function h_brochures_list(): void {
    $rows = db()->query(
        'SELECT b.id, b.provider, b.title, b.notes, b.original_name, b.mime, b.size,
                b.created_by, b.created_at
         FROM brochures b
         ORDER BY b.provider, b.title'
    )->fetchAll();
    foreach ($rows as &$r) {
        $r['id']       = (int) $r['id'];
        $r['size']     = (int) $r['size'];
        $r['has_file'] = $r['original_name'] !== null;
        $r['can_edit'] = can_edit_record($r['created_by']);
    }
    json_out(['ok' => true, 'brochures' => $rows]);
}

function h_brochure_get(): void {
    $id = (int) ($_GET['id'] ?? 0);
    $st = db()->prepare('SELECT * FROM brochures WHERE id = ?');
    $st->execute([$id]);
    $b = $st->fetch();
    if (!$b) json_error('Brochure not found.', 404);
    $b['can_edit'] = can_edit_record($b['created_by']);
    unset($b['stored_name']); // never expose the on-disk name
    json_out(['ok' => true, 'brochure' => $b]);
}

/** Create or update a brochure (multipart: provider, title, notes, optional file). */
function h_brochure_save(): void {
    $id       = (int) ($_POST['id'] ?? 0);
    $provider = clean($_POST['provider'] ?? null);
    $title    = clean($_POST['title'] ?? null);
    $notes    = clean($_POST['notes'] ?? null);
    if ($provider === null) json_error('Please choose a provider.', 422);
    if ($title === null)    json_error('A title is required.', 422);

    $hasUpload = !empty($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE;

    if ($id > 0) {
        $cur = db()->prepare('SELECT created_by, stored_name FROM brochures WHERE id = ?');
        $cur->execute([$id]);
        $old = $cur->fetch();
        if (!$old) json_error('Brochure not found.', 404);
        if (!can_edit_record($old['created_by'])) json_error('You can only edit brochures you added.', 403);

        if ($hasUpload) {
            [$stored, $orig, $mime, $size] = brochure_store_upload($_FILES['file']);
            db()->prepare('UPDATE brochures SET provider=?, title=?, notes=?, original_name=?, stored_name=?, mime=?, size=? WHERE id=?')
                ->execute([$provider, $title, $notes, $orig, $stored, $mime, $size, $id]);
            if ($old['stored_name']) { $p = uploads_dir() . '/' . $old['stored_name']; if (is_file($p)) @unlink($p); }
        } else {
            db()->prepare('UPDATE brochures SET provider=?, title=?, notes=? WHERE id=?')
                ->execute([$provider, $title, $notes, $id]);
        }
        log_activity('brochure_update', "Brochure #$id");
    } else {
        if (!$hasUpload) json_error('Please choose a file to upload.', 422);
        [$stored, $orig, $mime, $size] = brochure_store_upload($_FILES['file']);
        db()->prepare('INSERT INTO brochures (provider, title, notes, original_name, stored_name, mime, size, created_by) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$provider, $title, $notes, $orig, $stored, $mime, $size, current_user()['id']]);
        $id = (int) db()->lastInsertId();
        log_activity('brochure_create', "Brochure #$id");
    }
    json_out(['ok' => true, 'id' => $id]);
}

function h_brochure_delete(): void {
    $in = json_input();
    $id = (int) ($in['id'] ?? 0);
    $st = db()->prepare('SELECT created_by, stored_name FROM brochures WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) json_error('Brochure not found.', 404);
    if (!can_edit_record($row['created_by'])) json_error('You can only delete brochures you added.', 403);

    if ($row['stored_name']) { $p = uploads_dir() . '/' . $row['stored_name']; if (is_file($p)) @unlink($p); }
    db()->prepare('DELETE FROM brochures WHERE id = ?')->execute([$id]);
    log_activity('brochure_delete', "Brochure #$id");
    json_out(['ok' => true]);
}

/** Stream a brochure file inline (pdf/image) or as a download. Any logged-in role. */
function h_brochure_download(): void {
    require_login();
    $id = (int) ($_GET['id'] ?? 0);
    $st = db()->prepare('SELECT original_name, stored_name, mime FROM brochures WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row || !$row['stored_name']) { http_response_code(404); echo 'Not found'; exit; }

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

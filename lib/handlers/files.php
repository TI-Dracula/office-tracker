<?php
/** Secure file upload / download / delete. Files live outside the web root. */

const ALLOWED_EXT = ['pdf','png','jpg','jpeg','gif','webp','doc','docx','xls','xlsx','csv','txt'];
const INLINE_MIME = ['application/pdf','image/png','image/jpeg','image/gif','image/webp','text/plain'];

/** Map ext => safe content type for serving. */
function mime_for_ext(string $ext): string {
    static $map = [
        'pdf'=>'application/pdf','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg',
        'gif'=>'image/gif','webp'=>'image/webp','txt'=>'text/plain','csv'=>'text/csv',
        'doc'=>'application/msword',
        'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'=>'application/vnd.ms-excel',
        'xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

function h_file_upload(): void {
    $target   = $_POST['target']    ?? '';            // 'invoice' | 'project'
    $targetId = (int)($_POST['target_id'] ?? 0);
    $docType  = clean($_POST['doc_type'] ?? null) ?? 'Document';

    if (!in_array($target, ['invoice','project'], true)) json_error('Bad upload target.', 422);
    if ($targetId <= 0) json_error('Missing record id.', 422);
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        json_error('No file received (or it was too large for the server).', 422);
    }

    // Verify the parent record exists and the user may edit it
    $tbl = $target === 'invoice' ? 'invoices' : 'projects';
    $own = db()->prepare("SELECT created_by FROM $tbl WHERE id = ?");
    $own->execute([$targetId]);
    $row = $own->fetch();
    if (!$row) json_error('Record not found.', 404);
    if (!can_edit_record($row['created_by'])) json_error('You can only attach files to your own records.', 403);

    $f = $_FILES['file'];
    $maxBytes = (int)cfg('app.max_upload_mb', 15) * 1024 * 1024;
    if ($f['size'] > $maxBytes) json_error('File is larger than the ' . cfg('app.max_upload_mb', 15) . ' MB limit.', 422);

    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXT, true)) {
        json_error('That file type is not allowed.', 422);
    }

    if (!is_dir(uploads_dir())) @mkdir(uploads_dir(), 0775, true);
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = uploads_dir() . '/' . $stored;
    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        json_error('Could not save the file. Check folder permissions on the server.', 500);
    }

    $mime = mime_for_ext($ext);
    if ($target === 'invoice') {
        $st = db()->prepare('INSERT INTO invoice_files (invoice_id, original_name, stored_name, mime, size) VALUES (?,?,?,?,?)');
        $st->execute([$targetId, $f['name'], $stored, $mime, $f['size']]);
    } else {
        $st = db()->prepare('INSERT INTO project_files (project_id, doc_type, original_name, stored_name, mime, size) VALUES (?,?,?,?,?,?)');
        $st->execute([$targetId, $docType, $f['name'], $stored, $mime, $f['size']]);
    }
    log_activity('file_upload', "$target #$targetId: {$f['name']}");
    json_out(['ok' => true, 'id' => (int)db()->lastInsertId(), 'name' => $f['name']]);
}

/** Look up a file row across both file tables. */
function find_file(string $type, int $id): ?array {
    $tbl = $type === 'invoice' ? 'invoice_files' : 'project_files';
    $st = db()->prepare("SELECT * FROM $tbl WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function h_file_download(): void {
    require_login();
    $type = ($_GET['type'] ?? 'invoice') === 'project' ? 'project' : 'invoice';
    $id   = (int)($_GET['id'] ?? 0);
    $row  = find_file($type, $id);
    if (!$row) { http_response_code(404); echo 'Not found'; exit; }

    $path = uploads_dir() . '/' . basename($row['stored_name']);
    if (!is_file($path)) { http_response_code(404); echo 'File missing'; exit; }

    $ext  = strtolower(pathinfo($row['original_name'], PATHINFO_EXTENSION));
    $mime = $row['mime'] ?: mime_for_ext($ext);
    // View inline for PDFs/images; force download otherwise or when ?dl=1
    $forceDl = !empty($_GET['dl']) || !in_array($mime, INLINE_MIME, true);
    $disp = $forceDl ? 'attachment' : 'inline';

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . $disp . '; filename="' . rawurlencode($row['original_name']) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($path);
    exit;
}

function h_file_delete(): void {
    $in   = json_input();
    $type = ($in['type'] ?? 'invoice') === 'project' ? 'project' : 'invoice';
    $id   = (int)($in['id'] ?? 0);
    $row  = find_file($type, $id);
    if (!$row) json_error('File not found.', 404);

    // Check parent ownership
    if ($type === 'invoice') {
        $p = db()->prepare('SELECT created_by FROM invoices WHERE id = ?');
        $p->execute([$row['invoice_id']]);
    } else {
        $p = db()->prepare('SELECT created_by FROM projects WHERE id = ?');
        $p->execute([$row['project_id']]);
    }
    $parent = $p->fetch();
    if ($parent && !can_edit_record($parent['created_by'])) json_error('Not allowed.', 403);

    $path = uploads_dir() . '/' . basename($row['stored_name']);
    if (is_file($path)) @unlink($path);
    $tbl = $type === 'invoice' ? 'invoice_files' : 'project_files';
    db()->prepare("DELETE FROM $tbl WHERE id = ?")->execute([$id]);
    json_out(['ok' => true]);
}

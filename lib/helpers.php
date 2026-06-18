<?php
/** Small helper toolkit used across the app. */

/** Read a dotted config key, e.g. cfg('db.host') or cfg('app.currency_symbol', '₹'). */
function cfg(string $path, $default = null) {
    $node = $GLOBALS['__config'] ?? [];
    foreach (explode('.', $path) as $part) {
        if (is_array($node) && array_key_exists($part, $node)) {
            $node = $node[$part];
        } else {
            return $default;
        }
    }
    return $node;
}

/** Send a JSON response and stop. */
function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Send a JSON error and stop. */
function json_error(string $message, int $code = 400): void {
    json_out(['ok' => false, 'error' => $message], $code);
}

/** Decode a JSON request body into an array (for fetch() POSTs). */
function json_input(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** Escape for HTML output. */
function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/** Trim + null-empty a scalar input. */
function clean($v): ?string {
    if ($v === null) return null;
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

/** Settings stored in the DB (key/value). Falls back to config defaults. */
function setting_get(string $key, $default = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT k, v FROM settings')->fetchAll() as $r) {
            $cache[$r['k']] = $r['v'];
        }
    }
    return $cache[$key] ?? $default;
}

function setting_set(string $key, $value): void {
    $st = db()->prepare(
        'INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)'
    );
    $st->execute([$key, (string)$value]);
}

/** Current effective currency symbol (DB setting overrides config). */
function currency_symbol(): string {
    return (string) setting_get('currency_symbol', cfg('app.currency_symbol', '₹'));
}

/** ---- CSRF ---------------------------------------------------------------- */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void {
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)$sent)) {
        json_error('Your session expired. Please refresh the page and try again.', 419);
    }
}

/** Write a line to the activity log. */
function log_activity(string $action, string $detail = ''): void {
    try {
        $st = db()->prepare('INSERT INTO activity_log (user_id, action, detail) VALUES (?, ?, ?)');
        $st->execute([current_user()['id'] ?? null, $action, $detail]);
    } catch (Throwable $e) { /* logging must never break the request */ }
}

/** Absolute path to the protected uploads directory. */
function uploads_dir(): string {
    return ROOT . '/storage/uploads';
}

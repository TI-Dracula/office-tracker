<?php
/** Authentication & authorization. */

function current_user(): ?array {
    static $user = false;
    if ($user !== false) return $user;
    $uid = $_SESSION['uid'] ?? null;
    if (!$uid) return $user = null;
    $st = db()->prepare('SELECT id, name, username, email, role, active FROM users WHERE id = ? AND active = 1');
    $st->execute([$uid]);
    $row = $st->fetch();
    return $user = ($row ?: null);
}

function is_logged_in(): bool { return current_user() !== null; }
function is_admin(): bool { $u = current_user(); return $u && $u['role'] === 'admin'; }

/** Public-safe view of a user row (no password hash). */
function public_user(array $u): array {
    return [
        'id'       => (int)$u['id'],
        'name'     => $u['name'],
        'username' => $u['username'],
        'email'    => $u['email'] ?? null,
        'role'     => $u['role'],
    ];
}

/** True if the current user may edit/delete a record created by $createdBy. */
function can_edit_record($createdBy): bool {
    if (is_admin()) return true;
    $u = current_user();
    return $u && (int)$createdBy === (int)$u['id'];
}

function require_login(): void {
    if (!is_logged_in()) json_error('Please log in.', 401);
}

function require_admin(): void {
    require_login();
    if (!is_admin()) json_error('Admins only.', 403);
}

/** Attempt a login. Returns the user row or null. */
function attempt_login(string $username, string $password): ?array {
    $st = db()->prepare('SELECT * FROM users WHERE username = ? AND active = 1');
    $st->execute([$username]);
    $u = $st->fetch();
    if ($u && password_verify($password, $u['password_hash'])) {
        // Rehash if PHP's default cost changed
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            $up = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $up->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        unset($u['password_hash']);
        return $u;
    }
    return null;
}

function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

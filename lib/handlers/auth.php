<?php
/** Auth endpoints. (public_user / can_edit_record live in lib/auth.php) */

function h_login(): void {
    $in = json_input();
    $username = trim((string)($in['username'] ?? ''));
    $password = (string)($in['password'] ?? '');

    if ($username === '' || $password === '') {
        json_error('Enter your username and password.', 422);
    }

    // Gentle brute-force slowdown per session
    $_SESSION['login_fails'] = $_SESSION['login_fails'] ?? 0;
    if ($_SESSION['login_fails'] >= 5) {
        usleep(700000); // 0.7s
    }

    $u = attempt_login($username, $password);
    if (!$u) {
        $_SESSION['login_fails']++;
        json_error('Invalid username or password.', 401);
    }

    $_SESSION['login_fails'] = 0;
    log_activity('login', 'Signed in');
    json_out([
        'ok'       => true,
        'user'     => public_user($u),
        'csrf'     => csrf_token(),
        'is_admin' => $u['role'] === 'admin',
        'currency' => currency_symbol(),
    ]);
}

function h_logout(): void {
    log_activity('logout', 'Signed out');
    logout();
    json_out(['ok' => true]);
}

function h_me(): void {
    $u = current_user();
    if (!$u) {
        json_out(['ok' => true, 'user' => null]);
    }
    json_out([
        'ok'       => true,
        'user'     => public_user($u),
        'csrf'     => csrf_token(),
        'is_admin' => is_admin(),
        'currency' => currency_symbol(),
        'app_name' => setting_get('app_name', cfg('app.name', 'IBC Office Tracker')),
    ]);
}

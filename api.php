<?php
/**
 * Single JSON/file API entry point.  All client requests go to api.php?action=...
 */
require __DIR__ . '/lib/bootstrap.php';

require __DIR__ . '/lib/handlers/auth.php';
require __DIR__ . '/lib/handlers/invoices.php';
require __DIR__ . '/lib/handlers/projects.php';
require __DIR__ . '/lib/handlers/files.php';
require __DIR__ . '/lib/handlers/admin.php';

// action => [function, auth(public|user|admin), needs CSRF, must be POST]
$routes = [
    'login'           => ['h_login',            'public', false, true],
    'logout'          => ['h_logout',           'public', false, true],
    'me'              => ['h_me',               'public', false, false],

    'dashboard'       => ['h_dashboard',        'user',   false, false],

    'vendors_list'    => ['h_vendors_list',     'user',   false, false],
    'invoices_list'   => ['h_invoices_list',    'user',   false, false],
    'invoice_get'     => ['h_invoice_get',      'user',   false, false],
    'invoice_save'    => ['h_invoice_save',     'user',   true,  true],
    'invoice_delete'  => ['h_invoice_delete',   'user',   true,  true],
    'invoices_export' => ['h_invoices_export',  'user',   false, false],

    'locations_list'  => ['h_locations_list',   'user',   false, false],
    'location_save'   => ['h_location_save',    'admin',  true,  true],
    'projects_list'   => ['h_projects_list',    'user',   false, false],
    'project_get'     => ['h_project_get',      'user',   false, false],
    'project_save'    => ['h_project_save',     'user',   true,  true],
    'project_delete'  => ['h_project_delete',   'user',   true,  true],

    'file_upload'     => ['h_file_upload',      'user',   true,  true],
    'file_download'   => ['h_file_download',    'user',   false, false],
    'file_delete'     => ['h_file_delete',      'user',   true,  true],

    'users_list'      => ['h_users_list',       'admin',  false, false],
    'user_save'       => ['h_user_save',        'admin',  true,  true],
    'user_delete'     => ['h_user_delete',      'admin',  true,  true],

    'settings_get'    => ['h_settings_get',     'user',   false, false],
    'settings_save'   => ['h_settings_save',    'admin',  true,  true],
];

$action = $_GET['action'] ?? '';
if (!isset($routes[$action])) {
    json_error('Unknown action: ' . $action, 404);
}

[$fn, $auth, $needsCsrf, $mustPost] = $routes[$action];

if ($mustPost && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST required.', 405);
}
if ($auth === 'user')  require_login();
if ($auth === 'admin') require_admin();
if ($needsCsrf) csrf_check();

try {
    $fn();
} catch (Throwable $e) {
    error_log('API error in ' . $action . ': ' . $e->getMessage());
    json_error('Something went wrong on the server. Please try again.', 500);
}

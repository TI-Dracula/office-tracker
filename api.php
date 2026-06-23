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
require __DIR__ . '/lib/handlers/people.php';
require __DIR__ . '/lib/handlers/assets.php';
require __DIR__ . '/lib/handlers/brochures.php';

// action => [function, auth(public|user|editor|admin), needs CSRF, must be POST]
//   public = anyone · user = any logged-in (incl. viewer) · editor = non-viewer · admin = admin
$routes = [
    'login'           => ['h_login',            'public', false, true],
    'logout'          => ['h_logout',           'public', true,  true],
    'me'              => ['h_me',               'public', false, false],

    'dashboard'       => ['h_dashboard',        'editor', false, false],

    'vendors_list'    => ['h_vendors_list',     'editor', false, false],
    'invoices_list'   => ['h_invoices_list',    'editor', false, false],
    'invoice_get'     => ['h_invoice_get',      'editor', false, false],
    'invoice_save'    => ['h_invoice_save',     'editor', true,  true],
    'invoice_delete'  => ['h_invoice_delete',   'editor', true,  true],
    'invoices_export' => ['h_invoices_export',  'editor', false, false],

    'locations_list'  => ['h_locations_list',   'user',   false, false],
    'location_save'   => ['h_location_save',    'admin',  true,  true],
    'projects_list'   => ['h_projects_list',    'user',   false, false],
    'project_get'     => ['h_project_get',      'user',   false, false],
    'project_save'    => ['h_project_save',     'editor', true,  true],
    'project_delete'  => ['h_project_delete',   'editor', true,  true],

    'brochures_list'  => ['h_brochures_list',   'user',   false, false],
    'brochure_get'    => ['h_brochure_get',     'user',   false, false],
    'brochure_save'   => ['h_brochure_save',    'editor', true,  true],
    'brochure_delete' => ['h_brochure_delete',  'editor', true,  true],
    'brochure_download' => ['h_brochure_download', 'user', false, false],

    'assets_list'     => ['h_assets_list',      'editor', false, false],
    'asset_get'       => ['h_asset_get',        'editor', false, false],
    'asset_save'      => ['h_asset_save',       'editor', true,  true],
    'asset_delete'    => ['h_asset_delete',     'editor', true,  true],
    'assets_export'   => ['h_assets_export',    'editor', false, false],

    'people_list'     => ['h_people_list',      'editor', false, false],
    'person_save'     => ['h_person_save',      'admin',  true,  true],
    'person_delete'   => ['h_person_delete',    'admin',  true,  true],
    'm365_status'     => ['h_m365_status',      'admin',  false, false],
    'm365_sync'       => ['h_m365_sync',        'admin',  true,  true],

    'file_upload'     => ['h_file_upload',      'editor', true,  true],
    'file_download'   => ['h_file_download',    'user',   false, false],
    'file_delete'     => ['h_file_delete',      'editor', true,  true],

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
if ($auth === 'user')   require_login();
if ($auth === 'editor') require_editor();
if ($auth === 'admin')  require_admin();
if ($needsCsrf) csrf_check();

try {
    $fn();
} catch (Throwable $e) {
    error_log('API error in ' . $action . ': ' . $e->getMessage());
    json_error('Something went wrong on the server. Please try again.', 500);
}

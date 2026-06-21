<?php
/**
 * Sample configuration.
 *
 * You do NOT normally edit this file by hand — run /install.php once and it will
 * generate config.php for you. This sample is here for reference and disaster recovery.
 *
 * If you ever need to edit DB credentials manually: copy this file to "config.php"
 * and fill in the values from cPanel → MySQL Databases.
 */
return [
    'db' => [
        'host'    => 'localhost',          // InMotion shared hosting is almost always 'localhost'
        'name'    => 'YOUR_DB_NAME',        // e.g. usr1234_tracker
        'user'    => 'YOUR_DB_USER',        // e.g. usr1234_app
        'pass'    => 'YOUR_DB_PASSWORD',
        'charset' => 'utf8mb4',
    ],

    'app' => [
        'name'            => 'MOSS Operations',
        'currency'        => 'INR',
        'currency_symbol' => "\u{20B9}",   // ₹
        'timezone'        => 'Asia/Kolkata',
        'max_upload_mb'   => 15,
    ],

    /**
     * OPTIONAL — dormant by design. Manual invoice entry works without this.
     * If you ever decide to pay for an optional AI provider's API,
     * set enabled => true and paste a key here to turn on automatic invoice reading.
     * Leaving it off costs nothing.
     */
    'ai' => [
        'enabled' => false,
        'api_key' => '',
        'model'   => '',
    ],

    /**
     * Outgoing email for account invitations. Works out of the box via PHP mail()
     * on cPanel / InMotion. To send via SendGrid instead (better inbox delivery),
     * paste an API key below — no code changes needed.
     */
    'mail' => [
        'from'         => '',   // e.g. no-reply@moss.space  (defaults to no-reply@<your domain>)
        'from_name'    => '',   // e.g. MOSS IT  (defaults to the app name)
        'sendgrid_key' => '',   // SendGrid API key; leave blank to use PHP mail()
    ],

    // Auto-generated random string used to sign sessions/CSRF. install.php fills this.
    'secret' => 'CHANGE_ME',
];

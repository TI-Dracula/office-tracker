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
        'name'            => 'IBC Office Tracker',
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

    // Auto-generated random string used to sign sessions/CSRF. install.php fills this.
    'secret' => 'CHANGE_ME',
];

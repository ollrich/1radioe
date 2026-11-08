<?php
/**
 * 1radiœ configuration.
 *
 * Upload the folder "1radioe-private" OUTSIDE the web root and copy this file to
 * config.php inside it, for example:
 *
 *   /www/htdocs/w0123456/1radioe-private/config.php    <- here
 *   /www/htdocs/w0123456/eichhof.co/1radioe/            <- the app
 *
 * The app searches upwards from its own folder for 1radioe-private/config.php.
 * Leave password_hash and totp_secret empty: the setup wizard in the admin
 * generates both and shows you the lines to paste here.
 */
return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => '',        // database name from KAS
        'user' => '',
        'pass' => '',
    ],
    'admin' => [
        'user'          => 'ollie',
        'password_hash' => '',
        'totp_secret'   => '',
    ],
    // Public address of the app.
    'base_url'       => 'https://eichhof.co/1radioe',
    // Optional: where logos, covers and tracks are stored and their public address.
    // Default: a folder "1radioe-media" next to the app folder.
    // 'media_dir'   => '/www/htdocs/w0123456/eichhof.co/1radioe-media',
    // 'media_url'   => 'https://eichhof.co/1radioe-media',
    // Shown on the system page (Integration ID in the Sonos developer portal).
    'integration_id' => 'co.eichhof.radioe',
    'timezone'       => 'Europe/Berlin',
    // Only for local testing: allow stream tests against localhost and private networks.
    'allow_private_urls' => false,
];

<?php
/**
 * 1radiœ configuration.
 *
 * Copy this file to config.php inside a folder "1radioe-private" NEXT TO the git
 * checkout, never inside it:
 *
 *   /www/htdocs/w0123456/1radioe-private/config.php   <- here
 *   /www/htdocs/w0123456/1radioe.net/                 <- the checkout, the domain points at www/
 *
 * The app searches upwards from the checkout for 1radioe-private/config.php.
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
    // Public address without trailing slash, e.g. 'https://1radioe.net'.
    // Empty: derived from each request. Keep it empty while an old installation
    // under another domain still shares this config.
    'base_url'       => '',
    // Optional: another place for logos, covers and tracks and its public address.
    // Default: www/media in the checkout. Never set while an old installation shares this config.
    // 'media_dir'   => '/www/htdocs/w0123456/1radioe.net/www/media',
    // 'media_url'   => 'https://1radioe.net/media',
    // Shown on the system page (Integration ID in the Sonos developer portal).
    'integration_id' => 'co.eichhof.radioe',
    'timezone'       => 'Europe/Berlin',
    // Only for local testing: allow stream tests against localhost and private networks.
    'allow_private_urls' => false,
];

<?php
/**
 * 1radiœ configuration.
 *
 * Copy this file to config.php inside the folder "1radioe-private" in the checkout,
 * next to www/ (gitignored, outside the web root):
 *
 *   /www/htdocs/w0123456/1radioe.net/                  <- the checkout, the domain points at www/
 *   /www/htdocs/w0123456/1radioe.net/1radioe-private/config.php   <- here
 *
 * The app looks for 1radioe-private/config.php in the checkout first, then above it.
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
    // Key for the Bluesky app password and the Mastodon token, which are stored encrypted
    // in the database. 32 random bytes, base64. The admin page "Posting" shows a fresh one
    // while this is empty, or generate it with:
    //   php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
    // Changing it later means entering app password and token again.
    'secret_key'     => '',
    // Only for local testing: allow stream tests against localhost and private networks
    // (and plain http for the posting test servers).
    'allow_private_urls' => false,
];

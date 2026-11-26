<?php
/**
 * 1radiœ configuration.
 *
 * Copy this file to config.php inside the folder "1radioe-private" in the checkout,
 * next to www/ (gitignored, outside the web root):
 *
 *   <folder>/                              <- the checkout, the domain points at www/
 *   <folder>/1radioe-private/config.php    <- here
 *
 * The app looks for 1radioe-private/config.php in the checkout first, then above it.
 * Leave password_hash and totp_secret empty: the setup wizard in the admin
 * generates both and shows you the lines to paste here. Setup: DEPLOY-SETUP.md.
 */
return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => '',
        'user' => '',
        'pass' => '',
    ],
    'admin' => [
        'user'          => 'admin',
        'password_hash' => '',
        'totp_secret'   => '',
    ],
    // Public address without trailing slash, e.g. 'https://radio.example.org'.
    // Empty: derived from each request.
    'base_url'       => '',
    // Optional: another place for logos, covers and tracks and its public address.
    // Default: www/media in the checkout.
    // 'media_dir'   => '<folder>/www/media',
    // 'media_url'   => 'https://radio.example.org/media',
    // Shown on the system page: the Integration ID from the Sonos developer portal.
    'integration_id' => '',
    'timezone'       => 'Europe/Berlin',
    // Key for the Bluesky app password, the Mastodon token and the YouTube API key, which
    // are stored encrypted in the database. 32 random bytes, base64. The admin page
    // "Posting" shows a fresh one while this is empty, or generate it with:
    //   php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
    // Changing it later means entering them again.
    'secret_key'     => '',
    // Only for local testing: allow stream tests against localhost and private networks
    // (and plain http for the posting test servers).
    'allow_private_urls' => false,
];

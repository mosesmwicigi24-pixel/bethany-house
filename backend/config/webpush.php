<?php

/**
 * config/webpush.php
 *
 * Web Push (VAPID) configuration for the Bethany House PWA.
 *
 * Keys are generated once with:
 *   php artisan webpush:vapid
 *
 * Copy the output into your .env file:
 *   VAPID_SUBJECT=mailto:admin@bethanyhouse.com
 *   VAPID_PUBLIC_KEY=<generated public key>
 *   VAPID_PRIVATE_KEY=<generated private key>
 *   VITE_VAPID_PUBLIC_KEY=<same as VAPID_PUBLIC_KEY>
 *
 * VITE_VAPID_PUBLIC_KEY is the same value as VAPID_PUBLIC_KEY.
 * It's duplicated with the VITE_ prefix so Vite exposes it to the
 * frontend bundle (import.meta.env.VITE_VAPID_PUBLIC_KEY).
 */

return [
    'vapid' => [
        'subject'     => env('VAPID_SUBJECT', 'mailto:admin@bethanyhouse.co.ke'),
        'public_key'  => env('VAPID_PUBLIC_KEY', ''),
        'private_key' => env('VAPID_PRIVATE_KEY', ''),
    ],
];
<?php

/*
|--------------------------------------------------------------------------
| App links (native apps programme, Step 2)
|--------------------------------------------------------------------------
|
| The two well-known files that let a link to hub.bethanyhouse.co.ke open
| the native app instead of the browser:
|
|   /.well-known/apple-app-site-association   (iOS, iPadOS, macOS universal links)
|   /.well-known/assetlinks.json              (Android App Links)
|
| Served by App\Http\Controllers\WellKnownController. Each file is served
| only once its identifiers are set; until then the route answers 404 so a
| store validator never sees a half-filled file. None of these values is a
| secret: the team id and signing fingerprints are public by design.
|
*/

return [

    'apple' => [
        // The 10-character Apple Developer Team ID.
        'team_id'   => env('APPLE_TEAM_ID'),
        'bundle_id' => env('APPLE_BUNDLE_ID', 'ke.co.bethanyhouse.hub'),
    ],

    'android' => [
        'package' => env('ANDROID_PACKAGE', 'ke.co.bethanyhouse.hub'),
        // Comma-separated SHA-256 fingerprints of the signing certificates
        // (upload key and Play App Signing key), as `keytool -list -v` prints
        // them: "AB:CD:...".
        'sha256_fingerprints' => array_values(array_filter(array_map('trim', explode(',', (string) env('ANDROID_SHA256_FINGERPRINTS', ''))))),
    ],

    // Paths of the Hub the apps claim. Everything under /admin is the staff
    // console; the public customer pages (pay, quote, order, track) stay in
    // the browser on purpose and are excluded.
    'paths' => [
        'include' => ['/admin', '/admin/*'],
        'exclude' => ['/admin/pay/*', '/admin/quote/*', '/admin/order/*', '/admin/track/*', '/admin/privacy'],
    ],
];

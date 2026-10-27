<?php

/*
|--------------------------------------------------------------------------
| Native push (native apps programme, Step 2)
|--------------------------------------------------------------------------
|
| Credentials for the two platform push services the native apps use. Every
| value comes from the environment; nothing here is a secret and nothing
| secret is committed. When a service's credentials are not set, its sender
| is disabled: registrations are still accepted and stored, delivery is
| skipped with one log line, and nothing else changes.
|
| Web Push (VAPID) for the browser PWA stays in config/webpush.php. The Expo
| relay for the old store app shell stays in PushController until that shell
| is retired.
|
*/

return [

    'apns' => [
        // Token-based (JWT, ES256) authentication to APNs. Create the key in
        // the Apple Developer portal (Keys → Apple Push Notifications service).
        'key_id'      => env('APNS_KEY_ID'),
        'team_id'     => env('APNS_TEAM_ID'),
        // The .p8 private key: either its PEM contents (newlines may be
        // written as \n) or an absolute path to the file.
        'private_key' => env('APNS_PRIVATE_KEY'),
        // The apps' bundle identifier (the APNs "topic").
        'bundle_id'   => env('APNS_BUNDLE_ID', 'ke.co.bethanyhouse.hub'),
        // 'production' → api.push.apple.com, 'sandbox' → api.sandbox.push.apple.com
        'environment' => env('APNS_ENVIRONMENT', 'production'),
    ],

    'fcm' => [
        // Firebase project id (Project settings → General).
        'project_id'      => env('FCM_PROJECT_ID'),
        // A service account JSON with the Firebase Cloud Messaging API role:
        // either the JSON itself, its base64, or an absolute path to the file.
        'service_account' => env('FCM_SERVICE_ACCOUNT'),
        // Android notification channel the apps create at first launch.
        'channel_id'      => env('FCM_CHANNEL_ID', 'default'),
    ],

    // Seconds to wait for a push service before giving up on one message.
    'timeout' => (int) env('PUSH_TIMEOUT_SECONDS', 5),
];

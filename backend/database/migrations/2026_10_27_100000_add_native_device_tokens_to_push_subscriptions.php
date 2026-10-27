<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Native apps programme, Step 2 (owner approved 2026-10-27).
 *
 * push_subscriptions so far holds two kinds of registration: a browser's Web
 * Push subscription (token_type 'web') and the Expo shell's relay token
 * ('expo'). The native Apple and Android apps register directly with the
 * platform services, so two more kinds arrive:
 *
 *   token_type 'apns'  — Apple Push Notification service device token (iPhone, iPad, Mac)
 *   token_type 'fcm'   — Firebase Cloud Messaging registration token (Android phone, tablet)
 *
 * The existing NOT NULL + unique `endpoint` keeps carrying the identity:
 * 'apns:<token>' / 'fcm:<token>', exactly as 'expo:<token>' does today, so the
 * upsert-on-endpoint path and the "mark inactive on unregistered" path stay
 * one and the same. The new columns describe the device for the sessions
 * list and let the sender pick the right service without parsing the endpoint.
 *
 * Additive only. Rows already present are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('push_subscriptions', 'device_token')) {
                // APNs tokens are 64 hex chars; FCM registration tokens run to ~200.
                $table->string('device_token', 512)->nullable()->after('expo_token');
            }
            if (!Schema::hasColumn('push_subscriptions', 'platform')) {
                // ios | ipados | macos | android | web
                $table->string('platform', 16)->nullable()->after('token_type');
            }
            if (!Schema::hasColumn('push_subscriptions', 'device_model')) {
                $table->string('device_model', 120)->nullable()->after('platform');
            }
            if (!Schema::hasColumn('push_subscriptions', 'device_id')) {
                // The X-Device-Id the app sends (App\Services\Auth\Devices), so a
                // registration can be tied to the named device and revoked with it.
                $table->string('device_id', 64)->nullable()->after('device_model');
                $table->index('device_id');
            }
        });

        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->index(['token_type', 'is_active'], 'push_subscriptions_type_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropIndex('push_subscriptions_type_active_idx');
            $table->dropIndex(['device_id']);
            $table->dropColumn(['device_token', 'platform', 'device_model', 'device_id']);
        });
    }
};

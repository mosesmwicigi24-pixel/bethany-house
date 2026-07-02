<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds Expo Push Service support to the push_subscriptions table.
 *
 * New columns:
 *   expo_token  — Expo push token (e.g. "ExponentPushToken[xxx]"), NULL for web subs
 *   token_type  — 'web' (VAPID browser push) | 'expo' (React Native mobile app)
 *
 * Deployment:
 *   php artisan migrate
 *   php artisan optimize:clear
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            // Guard — safe to run on environments that already have these columns
            if (!Schema::hasColumn('push_subscriptions', 'expo_token')) {
                $table->string('expo_token', 255)
                    ->nullable()
                    ->unique()
                    ->after('auth')
                    ->comment('Expo Push Service token for React Native mobile app');
            }

            if (!Schema::hasColumn('push_subscriptions', 'token_type')) {
                $table->string('token_type', 10)
                    ->default('web')
                    ->after('expo_token')
                    ->comment('web = VAPID browser push, expo = Expo Push Service');
            }
        });

        // Back-fill existing rows — all pre-existing subscriptions are web push
        DB::table('push_subscriptions')
            ->whereNull('token_type')
            ->orWhere('token_type', '')
            ->update(['token_type' => 'web']);
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['expo_token', 'token_type']);
        });
    }
};
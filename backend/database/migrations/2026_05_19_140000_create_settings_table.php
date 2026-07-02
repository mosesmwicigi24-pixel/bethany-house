<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SettingController uses 'settings' table
        // SystemSetting model uses 'system_settings' table
        // We create BOTH and keep them in sync via the controller

        if (!Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->string('data_type', 20)->default('string');
                // string | boolean | integer | float | json | array
                $table->string('group', 50)->default('general');
                $table->string('description')->nullable();
                $table->boolean('is_public')->default(false);
                // Public settings are readable without auth (app_name, currencies, etc.)
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('system_settings')) {
            Schema::create('system_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->string('data_type', 20)->default('string');
                $table->string('description')->nullable();
                $table->boolean('is_public')->default(false);
                $table->timestamps();
            });
        }

        // ── Seed default settings ─────────────────────────────────────────
        $now = now();
        $defaults = [
            // Business identity
            ['key' => 'app_name',        'value' => 'Bethany House',          'data_type' => 'string',  'group' => 'business', 'is_public' => true],
            ['key' => 'app_tagline',     'value' => 'Quality fashion, made with love', 'data_type' => 'string', 'group' => 'business', 'is_public' => true],
            ['key' => 'app_email',       'value' => 'info@bethanyhouse.co.ke', 'data_type' => 'string',  'group' => 'business', 'is_public' => true],
            ['key' => 'app_phone',       'value' => '+254 700 000 000',        'data_type' => 'string',  'group' => 'business', 'is_public' => true],
            ['key' => 'app_address',     'value' => '',                        'data_type' => 'string',  'group' => 'business', 'is_public' => true],
            ['key' => 'app_city',        'value' => 'Nairobi',                 'data_type' => 'string',  'group' => 'business', 'is_public' => true],
            ['key' => 'app_country',     'value' => 'KE',                      'data_type' => 'string',  'group' => 'business', 'is_public' => true],
            ['key' => 'app_logo_url',    'value' => null,                      'data_type' => 'string',  'group' => 'business', 'is_public' => true],
            ['key' => 'app_favicon_url', 'value' => null,                      'data_type' => 'string',  'group' => 'business', 'is_public' => true],
            // Regional
            ['key' => 'app_timezone',       'value' => 'Africa/Nairobi', 'data_type' => 'string',  'group' => 'regional', 'is_public' => true],
            ['key' => 'default_currency',   'value' => 'KES',            'data_type' => 'string',  'group' => 'regional', 'is_public' => true],
            ['key' => 'default_language',   'value' => 'en',             'data_type' => 'string',  'group' => 'regional', 'is_public' => true],
            // Orders & POS
            ['key' => 'order_prefix',          'value' => 'BH-',  'data_type' => 'string',  'group' => 'orders', 'is_public' => false],
            ['key' => 'receipt_footer',        'value' => 'Thank you for shopping at Bethany House!', 'data_type' => 'string', 'group' => 'orders', 'is_public' => false],
            ['key' => 'low_stock_threshold',   'value' => '5',    'data_type' => 'integer', 'group' => 'inventory', 'is_public' => false],
            // Feature flags
            ['key' => 'tax_inclusive',         'value' => '0',    'data_type' => 'boolean', 'group' => 'tax',      'is_public' => true],
            ['key' => 'enable_guest_checkout', 'value' => '1',    'data_type' => 'boolean', 'group' => 'checkout', 'is_public' => true],
            ['key' => 'enable_reviews',        'value' => '1',    'data_type' => 'boolean', 'group' => 'products', 'is_public' => true],
            ['key' => 'maintenance_mode',      'value' => '0',    'data_type' => 'boolean', 'group' => 'system',   'is_public' => false],
            // Payment providers
            ['key' => 'mpesa_environment',   'value' => 'sandbox', 'data_type' => 'string', 'group' => 'payments', 'is_public' => false],
            ['key' => 'mpesa_shortcode',      'value' => '',        'data_type' => 'string', 'group' => 'payments', 'is_public' => false],
            // Mail
            ['key' => 'mail_from_name',    'value' => 'Bethany House',          'data_type' => 'string', 'group' => 'mail', 'is_public' => false],
            ['key' => 'mail_from_address', 'value' => 'noreply@bethanyhouse.co.ke', 'data_type' => 'string', 'group' => 'mail', 'is_public' => false],
        ];

        foreach ($defaults as $setting) {
            DB::table('settings')->insertOrIgnore(array_merge($setting, [
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('system_settings');
    }
};
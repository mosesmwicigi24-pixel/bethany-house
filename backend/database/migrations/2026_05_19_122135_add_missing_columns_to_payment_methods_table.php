<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── payment_methods ───────────────────────────────────────────────────
        // Has: display_order (just added), sort_order, configuration
        // Needs: is_default, type, icon, supported_currencies (as JSON array not string?)
        Schema::table('payment_methods', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_methods', 'is_default')) {
                $table->boolean('is_default')->default(false)->after('is_active');
            }
            if (!Schema::hasColumn('payment_methods', 'type')) {
                $table->string('type')->default('card')->after('provider');
                // Values: mobile_money | card | cash | bank_transfer
            }
            if (!Schema::hasColumn('payment_methods', 'icon')) {
                $table->string('icon')->nullable()->after('description');
            }
        });

        // ── currencies ────────────────────────────────────────────────────────
        // Has: is_base (not is_default), missing: symbol_position, thousand_separator, decimal_separator
        Schema::table('currencies', function (Blueprint $table) {
            if (!Schema::hasColumn('currencies', 'is_default')) {
                // Rename is_base → is_default or add is_default mirroring is_base
                $table->boolean('is_default')->default(false)->after('is_active');
            }
            if (!Schema::hasColumn('currencies', 'symbol_position')) {
                $table->string('symbol_position')->default('before')->after('symbol');
            }
            if (!Schema::hasColumn('currencies', 'thousand_separator')) {
                $table->string('thousand_separator', 5)->default(',')->after('decimal_places');
            }
            if (!Schema::hasColumn('currencies', 'decimal_separator')) {
                $table->string('decimal_separator', 5)->default('.')->after('thousand_separator');
            }
        });

        // Seed is_default from is_base
        \DB::table('currencies')->where('is_base', true)->update(['is_default' => true]);

        // ── languages ─────────────────────────────────────────────────────────
        // Has: sort_order - missing: direction, flag, native_name(already exists? no)
        Schema::table('languages', function (Blueprint $table) {
            if (!Schema::hasColumn('languages', 'direction')) {
                $table->string('direction', 3)->default('ltr')->after('is_active');
            }
            if (!Schema::hasColumn('languages', 'flag')) {
                $table->string('flag', 10)->nullable()->after('direction');
            }
            if (!Schema::hasColumn('languages', 'native_name')) {
                $table->string('native_name')->nullable()->after('name');
            }
        });

        // Seed native_name from name for existing records
        \DB::table('languages')
            ->whereNull('native_name')
            ->orWhere('native_name', '')
            ->orderBy('id')
            ->each(function ($lang) {
                $nativeNames = ['en' => 'English', 'fr' => 'Français', 'pt' => 'Português', 'sw' => 'Kiswahili'];
                $flags       = ['en' => '🇬🇧', 'fr' => '🇫🇷', 'pt' => '🇵🇹', 'sw' => '🇰🇪'];
                \DB::table('languages')->where('id', $lang->id)->update([
                    'native_name' => $nativeNames[$lang->code] ?? $lang->name,
                    'flag'        => $flags[$lang->code] ?? '🌐',
                ]);
            });

        // ── tax_rates ─────────────────────────────────────────────────────────
        // Has: tax_type (not type), missing: code, applies_to, is_default, type
        Schema::table('tax_rates', function (Blueprint $table) {
            if (!Schema::hasColumn('tax_rates', 'type')) {
                // Mirror tax_type as type (controller may use either)
                $table->string('type')->default('percentage')->after('rate');
            }
            if (!Schema::hasColumn('tax_rates', 'code')) {
                $table->string('code')->nullable()->after('name');
                // Make unique after seeding
            }
            if (!Schema::hasColumn('tax_rates', 'applies_to')) {
                $table->string('applies_to')->default('all')->after('type');
            }
            if (!Schema::hasColumn('tax_rates', 'is_default')) {
                $table->boolean('is_default')->default(false)->after('is_active');
            }
        });

        // Seed tax_rates.code from name for existing records
        \DB::table('tax_rates')
            ->whereNull('code')
            ->orWhere('code', '')
            ->orderBy('id')
            ->each(function ($tax) {
                $code = strtoupper(preg_replace('/[^A-Z0-9]/', '_', strtoupper($tax->name))) . '_' . $tax->id;
                \DB::table('tax_rates')->where('id', $tax->id)->update(['code' => $code]);

                // Sync type from tax_type
                \DB::table('tax_rates')->where('id', $tax->id)->update(['type' => $tax->tax_type ?? 'percentage']);
            });

        // ── outlets ───────────────────────────────────────────────────────────
        // All required columns already exist ✓

        // ── roles ─────────────────────────────────────────────────────────────
        // Has: user_type, description, is_active - missing: display_name, is_system
        Schema::table('roles', function (Blueprint $table) {
            if (!Schema::hasColumn('roles', 'display_name')) {
                $table->string('display_name')->nullable()->after('name');
            }
            if (!Schema::hasColumn('roles', 'is_system')) {
                $table->boolean('is_system')->default(false)->after('is_active');
            }
        });

        // Seed display_name from name for existing roles
        \DB::table('roles')
            ->whereNull('display_name')
            ->orWhere('display_name', '')
            ->orderBy('id')
            ->each(function ($role) {
                $display = ucwords(str_replace(['_', '-'], ' ', $role->name));
                \DB::table('roles')->where('id', $role->id)->update(['display_name' => $display]);
            });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn(array_filter(['is_default', 'type', 'icon'], fn($c) => Schema::hasColumn('payment_methods', $c)));
        });
        Schema::table('currencies', function (Blueprint $table) {
            $table->dropColumn(array_filter(['is_default', 'symbol_position', 'thousand_separator', 'decimal_separator'], fn($c) => Schema::hasColumn('currencies', $c)));
        });
        Schema::table('languages', function (Blueprint $table) {
            $table->dropColumn(array_filter(['direction', 'flag', 'native_name'], fn($c) => Schema::hasColumn('languages', $c)));
        });
        Schema::table('tax_rates', function (Blueprint $table) {
            $table->dropColumn(array_filter(['type', 'code', 'applies_to', 'is_default'], fn($c) => Schema::hasColumn('tax_rates', $c)));
        });
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(array_filter(['display_name', 'is_system'], fn($c) => Schema::hasColumn('roles', $c)));
        });
    }
};
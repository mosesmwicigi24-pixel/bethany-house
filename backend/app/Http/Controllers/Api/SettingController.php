<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Storage, Cache, Artisan};
use App\Services\TaxCalculationService;
use App\Services\ActivityLogService;
use App\Services\ImageService;

class SettingController extends Controller
{
    // ── Default settings - returned when table is empty ───────────────────────
    private const DEFAULTS = [
        'app_name'              => 'Bethany House',
        'app_tagline'           => 'Quality fashion, made with love',
        'app_email'             => 'info@bethanyhouse.co.ke',
        'app_phone'             => '+254 700 000 000',
        'app_address'           => '',
        'app_city'              => 'Nairobi',
        'app_country'           => 'KE',
        'app_timezone'          => 'Africa/Nairobi',
        'app_logo_url'          => null,
        'app_favicon_url'       => null,
        'default_currency'      => 'KES',
        'default_language'      => 'en',
        'order_prefix'          => 'BH-',
        'receipt_footer'        => 'Thank you for shopping at Bethany House!',
        'low_stock_threshold'   => 5,
        'tax_inclusive'         => false,
        'enable_guest_checkout' => true,
        'enable_reviews'        => true,
        'maintenance_mode'      => false,
    ];

    /**
     * GET /api/v1/admin/settings
     * Returns a flat key→value object matching BusinessSettings TypeScript interface.
     */

    /**
     * Shared helper — returns all settings with DEFAULTS merged in.
     * Used by this controller and any other controller that needs branding/config
     * (e.g. PublicPaymentController, ShipmentController) so defaults are never missed.
     */
    public static function getAll(): array
    {
        $fromDb = Cache::remember('app_settings', 300, function () {
            return DB::table('settings')->get()
                ->mapWithKeys(fn($s) => [$s->key => $s->value])
                ->toArray();
        });

        return array_merge(self::DEFAULTS, $fromDb);
    }

    public function index()
    {
        try {
            // Discard any stale cache entry that was stored as a non-Collection
            // (e.g. a plain array from an older version) to avoid ->toArray() errors.
            $cached = Cache::get('app_settings');
            if (!is_null($cached) && !($cached instanceof \Illuminate\Support\Collection)) {
                Cache::forget('app_settings');
            }

            $settings = Cache::remember('app_settings', 300, function () {
                return DB::table('settings')
                    ->get()
                    ->mapWithKeys(fn($s) => [$s->key => $this->cast($s)]);
            });

            // $settings may be a Collection or array depending on the cache driver
            $settingsArray = $settings instanceof \Illuminate\Support\Collection
                ? $settings->toArray()
                : (array) $settings;

            $merged = array_merge(self::DEFAULTS, $settingsArray);
        } catch (\Exception $e) {
            // If the settings table is missing expected columns or is empty,
            // fall back gracefully to the built-in defaults.
            $merged = self::DEFAULTS;
        }

        return response()->json(['settings' => $merged]);
    }

    /**
     * PUT /api/v1/admin/settings
     * Accepts flat key→value pairs matching BusinessSettings.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'app_name'              => 'sometimes|string|max:255',
            'app_tagline'           => 'sometimes|nullable|string|max:255',
            'app_email'             => 'sometimes|email|max:255',
            'app_phone'             => 'sometimes|nullable|string|max:30',
            'app_address'           => 'sometimes|nullable|string|max:500',
            'app_city'              => 'sometimes|nullable|string|max:100',
            'app_country'           => 'sometimes|string|max:3',
            'app_timezone'          => 'sometimes|string|max:50',
            'default_currency'      => 'sometimes|string|max:10',
            'default_language'      => 'sometimes|string|max:10',
            'order_prefix'          => 'sometimes|nullable|string|max:20',
            'receipt_footer'        => 'sometimes|nullable|string|max:500',
            'low_stock_threshold'   => 'sometimes|integer|min:0',
            'tax_inclusive'         => 'sometimes|boolean',
            'enable_guest_checkout' => 'sometimes|boolean',
            'enable_reviews'        => 'sometimes|boolean',
            'maintenance_mode'      => 'sometimes|boolean',
        ]);

        DB::beginTransaction();
        try {
            foreach ($validated as $key => $value) {
                $processed = is_bool($value) ? ($value ? '1' : '0') : (is_array($value) ? json_encode($value) : (string) ($value ?? ''));

                DB::table('settings')->updateOrInsert(
                    ['key' => $key],
                    [
                        'value'      => $processed,
                        'updated_at' => now(),
                        'created_at' => DB::raw("COALESCE(created_at, NOW())"),
                    ]
                );
            }

            Cache::forget('app_settings');
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to save settings.', 'error' => $e->getMessage()], 500);
        }

        // Phase 2 - if tax-related settings changed, bust the tax calculation cache
        if (array_key_exists('tax_inclusive', $validated) || array_key_exists('default_tax_rate', $validated)) {
            TaxCalculationService::invalidateGlobalCache();
        }

        // Outside transaction - failure here never rolls back the saved settings
        ActivityLogService::log('settings_updated', null, [
            'changed_keys' => array_keys($validated),
            'new_values'   => array_map(fn($v) => is_bool($v) ? ($v ? 'true' : 'false') : $v, $validated),
        ], 'Updated ' . count($validated) . ' system setting(s): ' . implode(', ', array_keys($validated)));

        return response()->json([
            'message'  => 'Settings saved successfully.',
            'settings' => array_merge(self::DEFAULTS, $validated),
        ]);
    }

    /**
     * POST /api/v1/admin/settings/logo
     * Upload business logo - returns public URL stored in app_logo_url setting.
     */
    public function uploadLogo(Request $request)
    {
        $request->validate([
            'logo' => 'required|image|mimes:jpeg,png,jpg,svg,webp|max:2048',
        ]);

        // Remove old logo
        $old = DB::table('settings')->where('key', 'app_logo_url')->value('value');
        if ($old) {
            app(ImageService::class)->delete($old, 'public');
        }

        $result = app(ImageService::class)->process($request->file('logo'), 'settings/logos', 'logo');
        $url    = $result['url'];

        DB::table('settings')->updateOrInsert(
            ['key' => 'app_logo_url'],
            ['value' => $url, 'updated_at' => now(), 'created_at' => DB::raw("COALESCE(created_at, NOW())")]
        );

        Cache::forget('app_settings');

        return response()->json(['message' => 'Logo uploaded.', 'url' => $url]);
    }

    /**
     * GET /api/v1/settings/languages  (public)
     * Returns languages from the languages table, falling back to hardcoded defaults.
     */
    public function languages()
    {
        try {
            $langs = DB::table('languages')
                ->where('is_active', true)
                ->orderBy('is_default', 'desc')
                ->orderBy('name')
                ->get();

            if ($langs->isNotEmpty()) {
                return response()->json(['data' => $langs]);
            }
        } catch (\Exception) {
            // languages table not yet migrated
        }

        // Fallback hardcoded
        return response()->json(['data' => [
            ['code' => 'en', 'name' => 'English',    'native_name' => 'English',   'direction' => 'ltr', 'flag' => '🇬🇧', 'is_default' => true,  'is_active' => true],
            ['code' => 'fr', 'name' => 'French',     'native_name' => 'Français',  'direction' => 'ltr', 'flag' => '🇫🇷', 'is_default' => false, 'is_active' => true],
            ['code' => 'pt', 'name' => 'Portuguese', 'native_name' => 'Português', 'direction' => 'ltr', 'flag' => '🇵🇹', 'is_default' => false, 'is_active' => true],
        ]]);
    }

    /**
     * GET /api/v1/settings/currencies  (public)
     * Returns active currencies from the currencies table.
     */
    public function currencies()
    {
        try {
            $currencies = DB::table('currencies')
                ->where('is_active', true)
                ->orderBy('is_base', 'desc')
                ->orderBy('code')
                ->get(['code', 'name', 'symbol', 'decimal_places', 'exchange_rate', 'is_base']);

            return response()->json(['data' => $currencies]);
        } catch (\Exception) {
            return response()->json(['data' => [
                ['code' => 'KES', 'name' => 'Kenyan Shilling', 'symbol' => 'KES', 'decimal_places' => 2, 'exchange_rate' => 1,      'is_base' => true],
                ['code' => 'USD', 'name' => 'US Dollar',       'symbol' => '$',   'decimal_places' => 2, 'exchange_rate' => 0.0077, 'is_base' => false],
            ]]);
        }
    }

    /**
     * GET /api/v1/settings/app-info  (public)
     * Returns only public-safe settings for the storefront.
     */
    public function appInfo()
    {
        try {
            $public = DB::table('settings')
                ->where('is_public', true)
                ->get()
                ->mapWithKeys(fn($s) => [$s->key => $this->cast($s)]);

            return response()->json($public);
        } catch (\Exception) {
            return response()->json([
                'app_name'         => self::DEFAULTS['app_name'],
                'default_currency' => self::DEFAULTS['default_currency'],
                'default_language' => self::DEFAULTS['default_language'],
            ]);
        }
    }

    /**
     * GET /api/v1/admin/settings/payment-providers
     */
    public function paymentProviders()
    {
        $keys = ['mpesa_environment', 'mpesa_shortcode', 'paystack_public_key', 'flutterwave_public_key'];
        $settings = DB::table('settings')->whereIn('key', $keys)->get()
            ->mapWithKeys(fn($s) => [$s->key => $s->value]);

        return response()->json(['data' => $settings]);
    }

    /**
     * PUT /api/v1/admin/settings/payment-providers
     */
    public function updatePaymentProviders(Request $request)
    {
        $validated = $request->validate([
            'mpesa_environment'    => 'sometimes|in:sandbox,production',
            'mpesa_shortcode'      => 'sometimes|string',
            'paystack_public_key'  => 'sometimes|string',
            'flutterwave_public_key' => 'sometimes|string',
        ]);

        foreach ($validated as $key => $value) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now(), 'created_at' => DB::raw("COALESCE(created_at, NOW())")]
            );
        }

        Cache::forget('app_settings');
        ActivityLogService::log('payment_providers_updated', null, [
            'changed_keys' => array_keys($validated),
        ], 'Payment provider settings updated: ' . implode(', ', array_keys($validated)));
        return response()->json(['message' => 'Payment providers updated.']);
    }

    /**
     * GET /api/v1/admin/settings/email
     */
    public function emailSettings()
    {
        $keys = ['mail_from_name', 'mail_from_address', 'mail_mailer', 'mail_host', 'mail_port'];
        $settings = DB::table('settings')->whereIn('key', $keys)->get()
            ->mapWithKeys(fn($s) => [$s->key => $s->value]);

        return response()->json(['data' => $settings]);
    }

    /**
     * PUT /api/v1/admin/settings/email
     */
    public function updateEmailSettings(Request $request)
    {
        $validated = $request->validate([
            'mail_from_name'    => 'sometimes|string|max:100',
            'mail_from_address' => 'sometimes|email',
            'mail_mailer'       => 'sometimes|in:smtp,mailgun,sendgrid,log',
            'mail_host'         => 'sometimes|string',
            'mail_port'         => 'sometimes|integer',
        ]);

        foreach ($validated as $key => $value) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => (string) $value, 'updated_at' => now(), 'created_at' => DB::raw("COALESCE(created_at, NOW())")]
            );
        }

        ActivityLogService::log('email_settings_updated', null, [
            'changed_keys' => array_keys($validated),
        ], 'Email settings updated: ' . implode(', ', array_keys($validated)));
        return response()->json(['message' => 'Email settings updated.']);
    }

    /**
     * POST /api/v1/admin/settings/test-email
     */
    public function testEmail(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        // TODO: Send test email via configured mailer
        return response()->json(['message' => 'Test email sent to ' . $request->email]);
    }

    /**
     * GET /api/v1/admin/settings/tax
     *
     * Phase 2: Returns the global tax_inclusive flag, the default rate,
     * and all active tax rates so the settings page can display a complete picture.
     */
    public function taxSettings()
    {
        $taxInclusive = DB::table('settings')->where('key', 'tax_inclusive')->value('value');
        $defaultRate  = DB::table('tax_rates')->where('is_default', true)->where('is_active', true)->first();
        $allRates     = DB::table('tax_rates')->where('is_active', true)->orderBy('is_default', 'desc')->orderBy('name')->get();

        return response()->json([
            'tax_inclusive'    => filter_var($taxInclusive, FILTER_VALIDATE_BOOLEAN),
            'default_tax_rate' => $defaultRate,
            'tax_rates'        => $allRates,
        ]);
    }

    /**
     * GET /api/v1/admin/pos/checkout-config  (pos.access permission)
     *
     * Read-only reference data the POS terminal needs at checkout, beyond
     * what /settings/tax already exposes - specifically app_country, used
     * to detect international orders (compared against the customer's
     * selected country). Bundled with tax_inclusive/default_tax_rate/
     * tax_rates here too so PosPage only needs one query instead of two
     * for this category of data.
     *
     * Exists because pos_clerk / outlet_manager have pos.access but not
     * settings.view, so they can't call index() or taxSettings() above
     * (both role/permission-gated to admin/super_admin) - those calls were
     * silently 403ing and PosPage was falling back to hardcoded defaults
     * (taxInclusive = false, HOME_COUNTRY = "KE") for every non-admin user.
     *
     * Reuses the same cached 'app_settings' read as index(), so this stays
     * in sync with the admin Settings page with no duplicate cache key.
     */
    public function posCheckoutConfig()
    {
        $cached = Cache::get('app_settings');
        if (!is_null($cached) && !($cached instanceof \Illuminate\Support\Collection)) {
            Cache::forget('app_settings');
        }

        $settings = Cache::remember('app_settings', 300, function () {
            return DB::table('settings')
                ->get()
                ->mapWithKeys(fn($s) => [$s->key => $this->cast($s)]);
        });

        $settingsArray = $settings instanceof \Illuminate\Support\Collection
            ? $settings->toArray()
            : (array) $settings;

        $merged = array_merge(self::DEFAULTS, $settingsArray);

        $taxInclusive = DB::table('settings')->where('key', 'tax_inclusive')->value('value');
        $defaultRate  = DB::table('tax_rates')->where('is_default', true)->where('is_active', true)->first();
        $allRates     = DB::table('tax_rates')->where('is_active', true)->orderBy('is_default', 'desc')->orderBy('name')->get();

        return response()->json([
            'app_country'      => $merged['app_country'] ?? 'KE',
            'tax_inclusive'    => filter_var($taxInclusive, FILTER_VALIDATE_BOOLEAN),
            'default_tax_rate' => $defaultRate,
            'tax_rates'        => $allRates,
        ]);
    }

    /**
     * PUT /api/v1/admin/settings/languages

     */
    public function updateLanguages(Request $request)
    {
        $validated = $request->validate(['languages' => 'required|array']);
        DB::table('settings')->updateOrInsert(
            ['key' => 'enabled_languages'],
            ['value' => json_encode($validated['languages']), 'updated_at' => now(), 'created_at' => DB::raw("COALESCE(created_at, NOW())")]
        );
        Cache::forget('app_settings');
        ActivityLogService::log('languages_updated', null, [
            'languages' => $validated['languages'],
        ], 'Enabled languages updated');
        return response()->json(['message' => 'Languages updated.']);
    }

    /**
     * PUT /api/v1/admin/settings/currencies
     */
    public function updateCurrencies(Request $request)
    {
        $validated = $request->validate(['currencies' => 'required|array']);
        DB::table('settings')->updateOrInsert(
            ['key' => 'enabled_currencies'],
            ['value' => json_encode($validated['currencies']), 'updated_at' => now(), 'created_at' => DB::raw("COALESCE(created_at, NOW())")]
        );
        Cache::forget('app_settings');
        return response()->json(['message' => 'Currencies updated.']);
    }

    /**
     * POST /api/v1/admin/settings/cache/clear
     */
    public function clearCache()
    {
        try {
            Artisan::call('cache:clear');
            Artisan::call('config:clear');
            Artisan::call('view:clear');
            Cache::forget('app_settings');
        } catch (\Exception $e) {
            return response()->json(['message' => 'Cache clear failed: ' . $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Cache cleared successfully.']);
    }

    /**
     * GET /api/v1/admin/settings/maintenance
     */
    public function maintenanceMode()
    {
        $enabled = DB::table('settings')->where('key', 'maintenance_mode')->value('value');
        return response()->json(['maintenance_mode' => filter_var($enabled, FILTER_VALIDATE_BOOLEAN)]);
    }

    /**
     * POST /api/v1/admin/settings/maintenance/toggle
     */
    public function toggleMaintenanceMode()
    {
        $current = DB::table('settings')->where('key', 'maintenance_mode')->value('value');
        $new     = filter_var($current, FILTER_VALIDATE_BOOLEAN) ? '0' : '1';

        DB::table('settings')->updateOrInsert(
            ['key' => 'maintenance_mode'],
            ['value' => $new, 'updated_at' => now(), 'created_at' => DB::raw("COALESCE(created_at, NOW())")]
        );

        Cache::forget('app_settings');
        return response()->json(['maintenance_mode' => $new === '1', 'message' => 'Maintenance mode ' . ($new === '1' ? 'enabled' : 'disabled') . '.']);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Cast a settings row value to its proper PHP type.
     */
    private function cast(object $setting): mixed
    {
        // property_exists() is required here: stdClass rows from DB::table() throw
        // a fatal error in PHP 8 when you access an undefined property — the ??
        // null-coalescing operator does NOT suppress that error.
        $dataType = property_exists($setting, 'data_type') ? ($setting->data_type ?? 'string') : 'string';
        $value    = property_exists($setting, 'value')     ? $setting->value                   : null;

        // When the table has no data_type column, infer types from known key names
        // so boolean and integer settings are still returned with the correct type.
        if ($dataType === 'string') {
            $key = property_exists($setting, 'key') ? ($setting->key ?? '') : '';
            if (in_array($key, ['tax_inclusive', 'enable_guest_checkout', 'enable_reviews', 'maintenance_mode'])) {
                $dataType = 'boolean';
            } elseif ($key === 'low_stock_threshold') {
                $dataType = 'integer';
            }
        }

        return match ($dataType) {
            'boolean'        => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer'        => (int) $value,
            'float'          => (float) $value,
            'json', 'array'  => json_decode($value, true),
            default          => $value,
        };
    }

    private function logActivity(Request $request, string $action, string $description): void
    {
        try {
            DB::table('activity_log')->insert([
                'user_id'     => $request->user()->id,
                'action'      => $action,
                'description' => $description,
                'ip_address'  => $request->ip(),
                'created_at'  => now(),
            ]);
        } catch (\Exception) {
            // activity_log table not yet migrated - ignore
        }
    }
}
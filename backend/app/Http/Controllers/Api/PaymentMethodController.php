<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentMethodController extends Controller
{
    // Actual DB columns:
    // id, code, name, description, provider, is_active, supported_currencies,
    // configuration (NOT config), sort_order, display_order,
    // is_default, type, icon
    //
    // Does NOT exist: supported_countries, config

    /**
     * GET /api/v1/payment-methods  (public)
     * Returns active methods filtered by currency.
     */
    public function available(Request $request)
    {
        $currency = $request->get('currency', 'KES');

        $methods = DB::table('payment_methods')
            ->where('is_active', true)
            ->orderBy('display_order')
            ->get()
            ->filter(function ($method) use ($currency) {
                $currencies = json_decode($method->supported_currencies ?? '[]', true);
                return empty($currencies) || in_array($currency, $currencies);
            })
            ->map(function ($method) {
                $method->supported_currencies = json_decode($method->supported_currencies ?? '[]', true);
                // Never expose gateway credentials publicly
                unset($method->configuration);
                return $method;
            })
            ->values();

        return response()->json(['data' => $methods]);
    }

    /**
     * GET /api/v1/admin/payment-methods-management  (admin)
     * Returns all methods. Credentials masked.
     */
    public function index()
    {
        $methods = DB::table('payment_methods')
            ->orderBy('display_order')
            ->get()
            ->map(fn ($m) => $this->format($m));

        return response()->json(['data' => $methods]);
    }

    /**
     * GET /api/v1/admin/payment-methods-management/{id}
     */
    public function show($id)
    {
        $method = DB::table('payment_methods')->find($id);

        if (!$method) {
            return response()->json(['message' => 'Payment method not found.'], 404);
        }

        return response()->json(['payment_method' => $this->format($method)]);
    }

    /**
     * POST /api/v1/admin/payment-methods-management
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'code'                 => 'required|string|max:50|unique:payment_methods,code',
            'name'                 => 'required|string|max:100',
            'description'          => 'nullable|string',
            'type'                 => 'required|in:card,mobile_money,bank_transfer,cash,wallet',
            'provider'             => 'nullable|string|max:100',
            'icon'                 => 'nullable|string|max:100',
            'supported_currencies' => 'nullable|array',
            'supported_currencies.*' => 'string|max:10',
            'is_active'            => 'sometimes|boolean',
            'is_default'           => 'sometimes|boolean',
            'sort_order'           => 'nullable|integer|min:0',
        ]);

        // If setting as default, clear existing default
        if (!empty($validated['is_default'])) {
            DB::table('payment_methods')->update(['is_default' => false]);
        }

        $maxOrder = DB::table('payment_methods')->max('display_order') ?? 0;

        $id = DB::table('payment_methods')->insertGetId([
            'code'                 => $validated['code'],
            'name'                 => $validated['name'],
            'description'          => $validated['description'] ?? null,
            'type'                 => $validated['type'],
            'provider'             => $validated['provider'] ?? null,
            'icon'                 => $validated['icon'] ?? null,
            'supported_currencies' => json_encode($validated['supported_currencies'] ?? ['KES']),
            'configuration'        => json_encode([]),      // DB column is 'configuration' not 'config'
            'is_active'            => $validated['is_active'] ?? true,
            'is_default'           => $validated['is_default'] ?? false,
            'sort_order'           => $validated['sort_order'] ?? ($maxOrder + 1),
            'display_order'        => $validated['sort_order'] ?? ($maxOrder + 1),
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $method = DB::table('payment_methods')->find($id);

        try {
            ActivityLogService::log('payment_method_created', null, [
                'method_id' => $id,
                'code'      => $validated['code'],
                'name'      => $validated['name'],
                'type'      => $validated['type'],
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message'        => 'Payment method created successfully.',
            'payment_method' => $this->format($method),
        ], 201);
    }

    /**
     * PUT /api/v1/admin/payment-methods-management/{id}
     */
    public function update(Request $request, $id)
    {
        $method = DB::table('payment_methods')->find($id);

        if (!$method) {
            return response()->json(['message' => 'Payment method not found.'], 404);
        }

        $validated = $request->validate([
            'name'                 => 'sometimes|string|max:100',
            'description'          => 'nullable|string',
            'type'                 => 'sometimes|in:card,mobile_money,bank_transfer,cash,wallet',
            'provider'             => 'nullable|string|max:100',
            'icon'                 => 'nullable|string|max:100',
            'supported_currencies' => 'sometimes|array',
            'supported_currencies.*' => 'string|max:10',
            'is_active'            => 'sometimes|boolean',
            'is_default'           => 'sometimes|boolean',
            'sort_order'           => 'nullable|integer|min:0',
        ]);

        $update = ['updated_at' => now()];

        foreach (['name', 'description', 'type', 'provider', 'icon', 'is_active', 'is_default'] as $col) {
            if (array_key_exists($col, $validated)) {
                $update[$col] = $validated[$col];
            }
        }

        if (isset($validated['supported_currencies'])) {
            $update['supported_currencies'] = json_encode($validated['supported_currencies']);
        }

        if (isset($validated['sort_order'])) {
            $update['sort_order']    = $validated['sort_order'];
            $update['display_order'] = $validated['sort_order'];
        }

        // If setting as default, clear others first
        if (!empty($validated['is_default'])) {
            DB::table('payment_methods')->where('id', '!=', $id)->update(['is_default' => false]);
        }

        DB::table('payment_methods')->where('id', $id)->update($update);

        try {
            ActivityLogService::log('payment_method_updated', null, [
                'method_id' => $id,
                'code'      => $method->code,
                'changes'   => array_keys(array_diff_key($update, ['updated_at' => 1])),
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message'        => 'Payment method updated successfully.',
            'payment_method' => $this->format(DB::table('payment_methods')->find($id)),
        ]);
    }

    /**
     * DELETE /api/v1/admin/payment-methods-management/{id}
     */
    public function destroy($id)
    {
        $method = DB::table('payment_methods')->find($id);

        if (!$method) {
            return response()->json(['message' => 'Payment method not found.'], 404);
        }

        // Check if used in transactions
        try {
            $inUse = DB::table('payments')
                ->where('payment_method', $method->code)
                ->exists();

            if ($inUse) {
                return response()->json([
                    'message' => 'Cannot delete a payment method that has been used in transactions.',
                ], 422);
            }
        } catch (\Exception) {
            // payments table may not exist yet - skip check
        }

        DB::table('payment_methods')->where('id', $id)->delete();

        try {
            ActivityLogService::log('payment_method_deleted', null, [
                'method_id' => $id,
                'code'      => $method->code,
                'name'      => $method->name,
            ]);
        } catch (\Exception) {}

        return response()->json(['message' => 'Payment method deleted successfully.']);
    }

    /**
     * PUT /api/v1/admin/payment-methods-management/{id}/toggle
     */
    public function toggleStatus($id)
    {
        $method = DB::table('payment_methods')->find($id);

        if (!$method) {
            return response()->json(['message' => 'Payment method not found.'], 404);
        }

        if ($method->is_default && $method->is_active) {
            return response()->json([
                'message' => 'Cannot disable the default payment method.',
            ], 422);
        }

        $newStatus = !$method->is_active;

        DB::table('payment_methods')->where('id', $id)->update([
            'is_active'  => $newStatus,
            'updated_at' => now(),
        ]);

        try {
            ActivityLogService::log('payment_method_toggled', null, [
                'method_id' => $id,
                'code'      => $method->code,
                'is_active' => $newStatus,
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message'        => 'Payment method status updated.',
            'payment_method' => $this->format(DB::table('payment_methods')->find($id)),
        ]);
    }

    /**
     * PUT /api/v1/admin/payment-methods-management/{id}/config
     * Updates gateway credentials stored in 'configuration' column.
     * Accepts either 'configuration' or 'config' from the frontend.
     *
     * For known gateways (mpesa, paystack, flutterwave) credentials are also
     * mirrored into the settings table so the service classes can read them.
     */
    public function updateConfig(Request $request, $id)
    {
        $request->validate([
            'configuration' => 'required_without:config|array',
            'config'        => 'required_without:configuration|array',
        ]);

        // Accept either key name — frontend sends 'configuration', DB column is 'configuration'
        $incoming = $request->input('configuration') ?? $request->input('config');

        if (empty($incoming) || !is_array($incoming)) {
            return response()->json([
                'message' => 'The configuration field is required.',
                'errors'  => ['configuration' => ['The configuration field is required.']],
            ], 422);
        }

        $method = DB::table('payment_methods')->find($id);

        if (!$method) {
            return response()->json(['message' => 'Payment method not found.'], 404);
        }

        DB::table('payment_methods')->where('id', $id)->update([
            'configuration' => json_encode($incoming),
            'updated_at'    => now(),
        ]);

        // Mirror credentials into the settings table so service classes
        // (MpesaService, etc.) can read them without joining payment_methods.
        $settingsMap = match ($method->code) {
            'mpesa' => [
                'mpesa_consumer_key'    => $incoming['consumer_key']    ?? null,
                'mpesa_consumer_secret' => $incoming['consumer_secret'] ?? null,
                'mpesa_shortcode'       => $incoming['shortcode']       ?? null,
                'mpesa_passkey'         => $incoming['passkey']         ?? null,
                'mpesa_environment'     => $incoming['environment']     ?? null,
            ],
            'paystack' => [
                'paystack_public_key' => $incoming['public_key']  ?? null,
                'paystack_secret_key' => $incoming['secret_key']  ?? null,
            ],
            'flutterwave' => [
                'flutterwave_public_key'    => $incoming['public_key']     ?? null,
                'flutterwave_secret_key'    => $incoming['secret_key']     ?? null,
                'flutterwave_encryption_key'=> $incoming['encryption_key'] ?? null,
            ],
            default => [],
        };

        foreach ($settingsMap as $key => $value) {
            if ($value !== null) {
                DB::table('settings')->updateOrInsert(
                    ['key' => $key],
                    ['value' => $value, 'updated_at' => now(), 'created_at' => now()]
                );
            }
        }

        // Bust the MpesaService config cache so the next request picks up new values
        if ($method->code === 'mpesa') {
            \Illuminate\Support\Facades\Cache::forget('mpesa_config');
            \Illuminate\Support\Facades\Cache::forget('mpesa_access_token');
        }

        try {
            ActivityLogService::log('payment_method_config_updated', null, [
                'method_id'   => $id,
                'code'        => $method->code,
                'config_keys' => array_keys($incoming),
            ]);
        } catch (\Exception) {}

        return response()->json(['message' => 'Credentials updated successfully.']);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Format a payment method row for JSON response.
     * - Decodes JSON columns
     * - Masks sensitive credential keys (shows key names but not values)
     * - Normalises column name differences
     */
    private function format(object $method): array
    {
        $config = json_decode($method->configuration ?? '{}', true) ?? [];

        // Mask credential values - send key names only so React can show input fields
        $maskedConfig = [];
        foreach ($config as $key => $value) {
            $maskedConfig[$key] = !empty($value) ? '••••••••' : '';
        }

        return [
            'id'                   => $method->id,
            'code'                 => $method->code,
            'name'                 => $method->name,
            'description'          => $method->description,
            'type'                 => $method->type ?? 'card',
            'provider'             => $method->provider,
            'icon'                 => $method->icon ?? null,
            'is_active'            => (bool) $method->is_active,
            'is_default'           => (bool) ($method->is_default ?? false),
            'sort_order'           => $method->sort_order ?? $method->display_order ?? 0,
            'display_order'        => $method->display_order ?? $method->sort_order ?? 0,
            'supported_currencies' => json_decode($method->supported_currencies ?? '["KES"]', true),
            'config'               => $maskedConfig,   // React types use 'config' key
            'created_at'           => $method->created_at,
            'updated_at'           => $method->updated_at,
        ];
    }
}
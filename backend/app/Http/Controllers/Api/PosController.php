<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use App\Models\Outlet;
use App\Models\TaxRate;
use App\Models\Payment;
use App\Services\ActivityLogService;
use App\Services\TaxCalculationService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PosController extends Controller
{
    // --- Outlets --------------------------------------------------------------

    public function outlets(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Outlet::query()->where('is_active', true);

        // Non-admins only see their assigned outlet(s)
        if (!$this->isAdminUser($user)) {
            $assignedIds = $user->outlets()->pluck('outlets.id');
            if ($assignedIds->isEmpty()) {
                return response()->json(['data' => []]);
            }
            $query->whereIn('id', $assignedIds);
        }

        $outlets = $query
            ->select('id', 'name', 'code', 'outlet_type', 'address_line1', 'city', 'phone', 'email', 'country_code', 'is_pickup_location')
            ->orderBy('name')
            ->get()
            ->map(fn ($o) => [
                'id'            => $o->id,
                'name'          => $o->name,
                'code'          => $o->code,
                'address'       => trim("{$o->address_line1}, {$o->city}"),
                'city'          => $o->city,
                'phone'         => $o->phone,
                'currency_code' => $o->country_code === 'USA' ? 'USD' : 'KES',
            ]);

        return response()->json(['data' => $outlets]);
    }

    // --- Products -------------------------------------------------------------

    public function products(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outlet_id'   => 'required|exists:outlets,id',
            'category_id' => 'nullable|exists:categories,id',
            'per_page'    => 'nullable|integer|min:10|max:200',
            'currency'    => 'nullable|string|max:10',
        ]);

        $outletId   = (int) $validated['outlet_id'];
        $categoryId = $validated['category_id'] ?? null;
        $perPage    = $validated['per_page'] ?? 60;

        // Validate and resolve the requested price currency.
        $requestedCurrency = strtoupper($validated['currency'] ?? '');
        if ($requestedCurrency) {
            $isActive = DB::table('currencies')
                ->where('code', $requestedCurrency)->where('is_active', true)->exists();
            if (!$isActive) $requestedCurrency = '';
        }
        if (!$requestedCurrency) {
            $requestedCurrency = $this->resolveCurrency(Outlet::findOrFail($outletId));
        }

        $this->authoriseOutletAccess($request->user(), $outletId);

        $products = Product::with([
            'category:id,name_en',
            'translations' => fn ($q) => $q->where('language_code', 'en'),
            'images'       => fn ($q) => $q->where('is_primary', true)->orderBy('sort_order'),
            // Product-level prices (used for simple products with no variants)
            'prices'                  => fn ($q) => $q->whereNull('product_variant_id'),
            // Outlet-specific product-level rows (no variant)
            'inventoryItems'          => fn ($q) => $q->where('outlet_id', $outletId)->whereNull('product_variant_id'),
            // Warehouse/global product-level rows as fallback (outlet_id IS NULL)
            'warehouseInventoryItems' => fn ($q) => $q->whereNull('outlet_id')->whereNull('product_variant_id'),
            'variants'     => fn ($q) => $q->where('is_active', true)->with([
                'prices',
                // Outlet-specific variant rows
                'inventoryItems'          => fn ($iq) => $iq->where('outlet_id', $outletId),
                // Warehouse/global variant rows as fallback
                'warehouseInventoryItems' => fn ($iq) => $iq->whereNull('outlet_id'),
            ]),
        ])
            ->where('status', 'active')
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            // Only show products stocked at this outlet OR in the global warehouse.
            // A product is considered available for an outlet when it has at least
            // one inventory_items row scoped to that outlet or with outlet_id = NULL.
            ->where(function ($q) use ($outletId) {
                $q->whereHas('inventoryItems', fn ($iq) =>
                        $iq->where('outlet_id', $outletId)
                    )
                  ->orWhereHas('warehouseInventoryItems', fn ($wiq) =>
                        $wiq->whereNull('outlet_id')
                    );
            })
            ->orderBy('sort_order')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($products->items())->map(fn ($p) => $this->transformProduct($p, $outletId, $requestedCurrency)),
            'meta' => [
                'total'        => $products->total(),
                'current_page' => $products->currentPage(),
                'last_page'    => $products->lastPage(),
            ],
        ]);
    }

    public function searchProducts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q'         => 'required|string|min:1|max:100',
            'outlet_id' => 'required|exists:outlets,id',
            'currency'  => 'nullable|string|max:10',
        ]);

        $outletId = (int) $validated['outlet_id'];
        $q        = trim($validated['q']);

        $requestedCurrency = strtoupper($validated['currency'] ?? '');
        if ($requestedCurrency) {
            $isActive = DB::table('currencies')
                ->where('code', $requestedCurrency)->where('is_active', true)->exists();
            if (!$isActive) $requestedCurrency = '';
        }
        if (!$requestedCurrency) {
            $requestedCurrency = $this->resolveCurrency(Outlet::findOrFail($outletId));
        }

        $this->authoriseOutletAccess($request->user(), $outletId);

        $products = Product::with([
            'category:id,name_en',
            'translations' => fn ($tq) => $tq->where('language_code', 'en'),
            'images'       => fn ($iq) => $iq->where('is_primary', true),
            'prices'                  => fn ($pq) => $pq->whereNull('product_variant_id'),
            'inventoryItems'          => fn ($piq) => $piq->where('outlet_id', $outletId)->whereNull('product_variant_id'),
            'warehouseInventoryItems' => fn ($piq) => $piq->whereNull('outlet_id')->whereNull('product_variant_id'),
            'variants'     => fn ($vq) => $vq->where('is_active', true)->with([
                'prices',
                'inventoryItems'          => fn ($iiq) => $iiq->where('outlet_id', $outletId),
                'warehouseInventoryItems' => fn ($iiq) => $iiq->whereNull('outlet_id'),
            ]),
        ])
            ->where('status', 'active')
            // Only search products stocked at this outlet or the global warehouse
            ->where(function ($q2) use ($outletId) {
                $q2->whereHas('inventoryItems', fn ($iq) =>
                        $iq->where('outlet_id', $outletId)
                    )
                   ->orWhereHas('warehouseInventoryItems', fn ($wiq) =>
                        $wiq->whereNull('outlet_id')
                    );
            })
            ->where(function ($query) use ($q) {
                $query->where('sku', 'ILIKE', "%{$q}%")
                      ->orWhereHas('translations', fn ($tq) =>
                            $tq->where('language_code', 'en')
                               ->where('name', 'ILIKE', "%{$q}%")
                      )
                      ->orWhereHas('variants', fn ($vq) =>
                            $vq->where('sku', 'ILIKE', "%{$q}%")
                      );
            })
            ->limit(30)
            ->get();

        return response()->json([
            'data' => $products->map(fn ($p) => $this->transformProduct($p, $outletId, $requestedCurrency)),
        ]);
    }

    // --- Cash Register --------------------------------------------------------

    /**
     * GET /admin/pos/register/status?outlet_id=X
     *
     * USER-SCOPED: each cashier sees only their own open register at the outlet.
     * Two users at the same outlet each get independent register sessions.
     * Returns eod_submitted flag indicating whether the user has done their
     * EoD report today (required before closeRegister is allowed).
     */
    public function registerStatus(Request $request): JsonResponse
    {
        $validated = $request->validate(['outlet_id' => 'required|exists:outlets,id']);
        $outletId  = (int) $validated['outlet_id'];
        $user      = $request->user();

        $this->authoriseOutletAccess($user, $outletId);

        // Only fetch THIS user's register — not any open register on the outlet
        $register = CashRegister::with(['openedBy:id,first_name,last_name', 'closedBy:id,first_name,last_name'])
            ->where('outlet_id', $outletId)
            ->where('opened_by', $user->id)
            ->latest('opened_at')
            ->first();

        $hasOpen = $register && $register->status === 'open';

        // Check whether the user has submitted their EoD report for today
        $eodSubmitted = false;
        if ($hasOpen) {
            $eodSubmitted = DB::table('cash_register_eod_reports')
                ->where('register_id', $register->id)
                ->where('user_id', $user->id)
                ->whereDate('report_date', today())
                ->whereNotNull('submitted_at')
                ->exists();
        }

        return response()->json([
            'register'          => $register ? $this->transformRegister($register) : null,
            'has_open_register' => $hasOpen,
            'eod_submitted'     => $eodSubmitted,
        ]);
    }

    /**
     * POST /admin/pos/register/open
     *
     * USER-SCOPED: one open register per user per outlet.
     * Multiple cashiers can each open their own register at the same outlet.
     * The `user_id` column already exists on cash_registers (confirmed via
     * Schema::getColumnListing) - it just isn't in CashRegister::$fillable
     * yet, so Eloquent has been silently dropping it on every create() call.
     * See the model fix that adds it to $fillable; until that's applied,
     * this column will remain unpopulated even though the migration ran.
     *
     * MIGRATION: create cash_register_eod_reports table:
     *   Schema::create('cash_register_eod_reports', function (Blueprint $table) {
     *       $table->id();
     *       $table->foreignId('register_id')->constrained('cash_registers')->cascadeOnDelete();
     *       $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
     *       $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
     *       $table->date('report_date');
     *       $table->json('order_notes')->nullable();
     *       $table->text('sentiments')->nullable();
     *       $table->timestamp('submitted_at')->nullable();
     *       $table->timestamps();
     *       $table->unique(['register_id', 'user_id', 'report_date']);
     *   });
     */
    public function openRegister(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outlet_id'    => 'required|exists:outlets,id',
            'opening_cash' => 'required|numeric|min:0',
            'notes'        => 'nullable|string|max:500',
        ]);

        $outletId = (int) $validated['outlet_id'];
        $user     = $request->user();

        $this->authoriseOutletAccess($user, $outletId);

        $outlet = Outlet::findOrFail($outletId);

        DB::beginTransaction();
        try {
            // Check: THIS USER must not already have an open register at this outlet
            $existingForUser = CashRegister::where('outlet_id', $outletId)
                ->where('opened_by', $user->id)
                ->where('status', 'open')
                ->lockForUpdate()
                ->exists();

            if ($existingForUser) {
                DB::rollBack();
                return response()->json(['message' => 'You already have an open cash register for this outlet.'], 422);
            }

            $userName     = trim("{$user->first_name} {$user->last_name}") ?: $user->email;
            $registerName = $outlet->name . ' – ' . $userName . ' – ' . now()->format('d M Y');

            $register = CashRegister::create([
                'outlet_id'         => $outletId,
                'user_id'           => $user->id,   // column confirmed present in DB; not yet in CashRegister::$fillable - see model fix
                'register_name'     => $registerName,
                'opened_by'         => $user->id,
                'opening_balance'   => $validated['opening_cash'],
                'expected_cash'     => $validated['opening_cash'],
                'currency_code'     => $this->resolveCurrency($outlet),
                'status'            => 'open',
                'opening_notes'     => $validated['notes'] ?? null,
                'opened_at'         => now(),
                'closing_balance'   => 0,
                'actual_cash'       => 0,
                'total_sales'       => 0,
                'total_cash_sales'  => 0,
                'total_card_sales'  => 0,
                'total_mpesa_sales' => 0,
                'total_refunds'     => 0,
                'transaction_count' => 0,
            ]);

            DB::commit();

            try {
                ActivityLogService::log('register_opened', null, [
                    'register_id'   => $register->id,
                    'register_name' => $registerName,
                    'outlet_id'     => $outletId,
                    'outlet_name'   => $outlet->name,
                    'user_id'       => $user->id,
                    'user_name'     => $userName,
                    'opening_cash'  => $validated['opening_cash'],
                    'currency'      => $register->currency_code,
                ]);
            } catch (\Exception) {}

            return response()->json([
                'message'  => 'Cash register opened successfully.',
                'register' => $this->transformRegister($register->load('openedBy')),
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('POS open register failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to open register.'], 500);
        }
    }

    /**
     * POST /admin/pos/register/close
     *
     * USER-SCOPED: closes THIS user's register only.
     * GUARD: requires EoD report to be submitted for today first.
     * Returns 422 with requires_eod:true if not submitted — frontend opens EoD modal.
     */
    public function closeRegister(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outlet_id'    => 'required|exists:outlets,id',
            'closing_cash' => 'required|numeric|min:0',
            'notes'        => 'nullable|string|max:500',
        ]);

        $outletId = (int) $validated['outlet_id'];
        $user     = $request->user();

        $this->authoriseOutletAccess($user, $outletId);

        // Fetch THIS user's open register
        $register = CashRegister::where('outlet_id', $outletId)
            ->where('opened_by', $user->id)
            ->where('status', 'open')
            ->latest('opened_at')
            ->firstOrFail();

        // Guard: EoD report must be submitted before closing
        $eodSubmitted = DB::table('cash_register_eod_reports')
            ->where('register_id', $register->id)
            ->where('user_id', $user->id)
            ->whereDate('report_date', today())
            ->whereNotNull('submitted_at')
            ->exists();

        if (!$eodSubmitted) {
            return response()->json([
                'message'      => 'Please submit your End of Day report before closing the register.',
                'requires_eod' => true,
            ], 422);
        }

        $variance = $validated['closing_cash'] - ($register->expected_cash ?? $register->opening_balance ?? 0);

        // `variance` is not a column (nor fillable) — it was silently dropped.
        // The discrepancy is the `cash_difference` accessor (actual − expected),
        // valid once actual_cash is set below. $variance is kept for response/log.
        $register->update([
            'closed_by'       => $user->id,
            'closing_balance' => $validated['closing_cash'],
            'actual_cash'     => $validated['closing_cash'],
            'status'          => 'closed',
            'closing_notes'   => $validated['notes'] ?? null,
            'closed_at'       => now(),
        ]);

        try {
            ActivityLogService::log('register_closed', null, [
                'register_id'      => $register->id,
                'register_name'    => $register->register_name,
                'outlet_id'        => $outletId,
                'opening_balance'  => $register->opening_balance,
                'closing_balance'  => $validated['closing_cash'],
                'expected_cash'    => $register->expected_cash,
                'variance'         => $variance,
                'total_sales'      => $register->total_sales,
                'transaction_count'=> $register->transaction_count,
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message'  => 'Cash register closed successfully.',
            'register' => $this->transformRegister($register->fresh(['openedBy', 'closedBy'])),
            'variance' => $variance,
        ]);
    }

    public function registerHistory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            'per_page'  => 'nullable|integer|min:5|max:50',
        ]);

        $sessions = CashRegister::with(['openedBy:id,first_name,last_name', 'closedBy:id,first_name,last_name'])
            ->where('outlet_id', $validated['outlet_id'])
            ->latest('opened_at')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json($sessions);
    }

    // --- Sales ----------------------------------------------------------------

    public function createSale(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outlet_id'              => 'required|exists:outlets,id',
            'customer_id'            => 'nullable|exists:customers,id',
            'customer_first_name'    => 'nullable|string|max:255',
            'customer_last_name'     => 'nullable|string|max:255',
            'customer_phone'         => 'nullable|string|max:30',
            'customer_email'         => 'nullable|email|max:255',
            // New customer creation (id === -1 on frontend)
            'new_customer'                     => 'nullable|array',
            'new_customer.first_name'          => 'required_with:new_customer|string|max:100',
            'new_customer.last_name'           => 'nullable|string|max:100',
            'new_customer.phone'               => 'required_with:new_customer|string|max:30',
            'new_customer.email'               => 'nullable|email|max:255',
            // Country drives currency for international POS orders
            'customer_country_code'            => 'nullable|string|size:2',
            'items'                  => 'required|array|min:1',
            'items.*.variant_id'     => 'nullable|exists:product_variants,id',
            'items.*.product_id'     => 'nullable|exists:products,id',
            'items.*.quantity'       => 'required|integer|min:1',
            'items.*.unit_price'     => 'required|numeric|min:0',
            'items.*.discount_type'  => 'nullable|in:none,flat,percent',
            'items.*.discount_value' => 'nullable|numeric|min:0',
            'cart_discount_type'     => 'nullable|in:none,flat,percent',
            'cart_discount_value'    => 'nullable|numeric|min:0',
            'shipping_amount'        => 'nullable|numeric|min:0',
            // Single-payment fields (backwards compatible)
            'payment_method'         => 'nullable|in:cash,card,mpesa,bank_transfer,other',
            'payment_reference'      => 'nullable|string|max:100',
            'cash_received'          => 'nullable|numeric|min:0',
            // Split / partial payments array (takes precedence)
            'payments'               => 'nullable|array|min:1',
            'payments.*.method'      => 'required_with:payments|in:cash,card,mpesa,bank_transfer,other',
            'payments.*.amount'      => 'required_with:payments|numeric|min:0.01',
            'payments.*.reference'   => 'nullable|string|max:100',
            'payments.*.cash_received' => 'nullable|numeric|min:0',
            // Deposit mode
            'is_deposit'             => 'nullable|boolean',
            'deposit_amount'         => 'nullable|numeric|min:0.01',
            'notes'                  => 'nullable|string|max:1000',
            'tax_rate_id'            => 'nullable|exists:tax_rates,id',
            // Production items
            'production_items'                        => 'nullable|array',
            'production_items.*.variant_id'           => 'nullable|exists:product_variants,id',
            'production_items.*.product_id'           => 'required_with:production_items|exists:products,id',
            'production_items.*.quantity'             => 'required_with:production_items|integer|min:1',
            'production_items.*.production_notes'     => 'nullable|string|max:1000',
        ]);

        $user     = $request->user();
        $outletId = (int) $validated['outlet_id'];

        $this->authoriseOutletAccess($user, $outletId);

        // Resolve payment list - normalise single-method and split-payments array
        $paymentsInput = $validated['payments'] ?? null;
        if (!$paymentsInput) {
            // Backwards compatible single-payment
            $method = $validated['payment_method'] ?? 'cash';
            $paymentsInput = [[
                'method'        => $method,
                'amount'        => null, // will be filled with totalAmount after calculation
                'reference'     => $validated['payment_reference'] ?? null,
                'cash_received' => $validated['cash_received'] ?? null,
            ]];
        }

        // Load payment method types once so we can treat any method whose type is
        // 'cash' identically to the built-in 'cash' code throughout this request.
        $inputMethodCodes  = collect($paymentsInput)->pluck('method')->filter()->unique()->toArray();
        $pmMethodTypesSale = DB::table('payment_methods')
            ->whereIn('code', $inputMethodCodes)
            ->pluck('type', 'code');   // ['cash' => 'cash', 'mpesa' => 'mobile_money', …]

        // Helper: true for the built-in 'cash' code AND any custom method typed as 'cash'
        $isCashTypeSale = fn (string $code): bool =>
            $code === 'cash' || ($pmMethodTypesSale->get($code) === 'cash');

        // If any payment is cash (by code or type), THIS USER's register must be open
        // Look up THIS user's open register. A cash sale requires one; a non-cash
        // sale still posts to it when open, so card/mpesa totals and the
        // transaction count are captured (they were dropped when no cash present).
        $hasCash = collect($paymentsInput)->contains(fn ($p) => $isCashTypeSale($p['method'] ?? ''));
        $register = CashRegister::where('outlet_id', $outletId)
            ->where('opened_by', $user->id)
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();
        if ($hasCash && !$register) {
            return response()->json(['message' => 'Cash register is not open. Please open your register first.'], 422);
        }

        $outlet       = Outlet::findOrFail($outletId);

        // ── Currency / international resolution ────────────────────────────────
        $homeCountry         = DB::table('settings')->where('key', 'app_country')->value('value') ?? 'KE';
        $customerCountryCode = strtoupper($validated['customer_country_code'] ?? '');
        $isInternational     = $customerCountryCode !== '' && $customerCountryCode !== strtoupper($homeCountry);

        if ($customerCountryCode !== '') {
            $countryCurrency  = DB::table('countries')
                ->where('code', $customerCountryCode)
                ->value('default_currency_code');
            $activeCurrencies = DB::table('currencies')->where('is_active', true)->pluck('code')->toArray();
            if ($countryCurrency && (empty($activeCurrencies) || in_array($countryCurrency, $activeCurrencies))) {
                $currencyCode = $countryCurrency;
            } else {
                $currencyCode = $this->resolveCurrency($outlet);
            }
        } else {
            $currencyCode = $this->resolveCurrency($outlet);
        }

        // Phase 2 — tax mode from global setting; per-item rates resolved by TaxCalculationService
        $taxInclusive = TaxCalculationService::isTaxInclusive();

        DB::beginTransaction();
        try {
            // -- 1. Stock check & per-item totals ------------------------------
            $itemsData    = [];
            $itemSubtotal = 0;

            foreach ($validated['items'] as $item) {
                // Resolve variant/product — variant_id is null for simple products.
                $variantId    = $item['variant_id'] ?? null;
                $variantModel = $variantId ? ProductVariant::find($variantId) : null;
                $productId    = $variantModel?->product_id ?? (int)($item['product_id'] ?? 0);
                $candidateRows = [];

                if ($variantId) {
                    // 1. Outlet-specific variant row
                    $row = InventoryItem::where('outlet_id', $outletId)
                        ->where('product_variant_id', $variantId)
                        ->lockForUpdate()->first();
                    if ($row) $candidateRows[] = $row;
                }

                if ($productId) {
                    // 2. Outlet-specific product-level row
                    $row = InventoryItem::where('outlet_id', $outletId)
                        ->where('product_id', $productId)
                        ->whereNull('product_variant_id')
                        ->lockForUpdate()->first();
                    if ($row) $candidateRows[] = $row;

                    if ($variantId) {
                        // 3. Warehouse variant row (outlet_id IS NULL)
                        $row = InventoryItem::whereNull('outlet_id')
                            ->where('product_variant_id', $variantId)
                            ->lockForUpdate()->first();
                        if ($row) $candidateRows[] = $row;
                    }

                    // 4. Warehouse product-level row
                    $row = InventoryItem::whereNull('outlet_id')
                        ->where('product_id', $productId)
                        ->whereNull('product_variant_id')
                        ->lockForUpdate()->first();
                    if ($row) $candidateRows[] = $row;
                }

                $inventory = collect($candidateRows)
                    ->sortByDesc(fn ($i) => $i->quantity_available)
                    ->first();

                $available = $inventory ? $inventory->quantity_available : 0;

                if (!$inventory || $available < $item['quantity']) {
                    DB::rollBack();
                    $name = $variantModel?->product?->translations->first()?->name
                         ?? ($productId ? Product::with('translations')->find($productId)?->translations->first()?->name : null)
                         ?? "Product #{$productId}";
                    return response()->json([
                        'message' => "Insufficient stock for \"{$name}\". Available: {$available}.",
                    ], 422);
                }

                $lineBase     = $item['unit_price'] * $item['quantity'];
                $discType     = $item['discount_type'] ?? 'none';
                $discVal      = (float) ($item['discount_value'] ?? 0);
                $lineDiscount = match ($discType) {
                    'flat'    => min($discVal, $lineBase),
                    'percent' => ($lineBase * $discVal) / 100,
                    default   => 0.0,
                };
                $lineSubtotal  = $lineBase - $lineDiscount;
                $itemSubtotal += $lineSubtotal;

                // Phase 2 — per-product tax via TaxCalculationService
                $taxCalcLine = TaxCalculationService::calculateLine(
                    $item['unit_price'],
                    $item['quantity'],
                    $productId,
                    $taxInclusive
                );
                $lineTaxAmount = $taxCalcLine['tax_amount'];
                if (!$taxInclusive) {
                    $lineSubtotalForOrder = $lineSubtotal + round($lineTaxAmount, 2);
                } else {
                    $lineSubtotalForOrder = $lineSubtotal;
                }

                // Fetch product name for the order item snapshot
                $variant = $variantModel ? $variantModel->load('product.translations') : null;
                $productName = $variant?->product?->translations->firstWhere('language_code', 'en')?->name
                            ?? $variant?->product?->translations->first()?->name
                            ?? ($productId ? Product::with('translations')->find($productId)?->translations->firstWhere('language_code', 'en')?->name : null)
                            ?? 'Unknown';

                $itemsData[] = [
                    'variant'            => $variant,
                    'product_name'       => $productName,
                    'variant_id'         => $variantId,
                    'product_id'         => $productId,
                    'quantity'           => $item['quantity'],
                    'unit_price'         => $item['unit_price'],
                    'discount_type'      => $discType,
                    'discount_value'     => $discVal,
                    'discount_amount'    => round($lineDiscount, 2),
                    'tax_amount'         => round($lineTaxAmount, 2),
                    'total_price'        => round($lineSubtotalForOrder, 2),
                    'inventory'          => $inventory,
                ];
            }

            // -- 2. Cart-level discount & totals -------------------------------
            $cartDiscType = $validated['cart_discount_type'] ?? 'none';
            $cartDiscVal  = (float) ($validated['cart_discount_value'] ?? 0);
            $cartDiscount = match ($cartDiscType) {
                'flat'    => min($cartDiscVal, $itemSubtotal),
                'percent' => ($itemSubtotal * $cartDiscVal) / 100,
                default   => 0.0,
            };

            $afterDiscount = $itemSubtotal - $cartDiscount;
            // Phase 2 — total tax is sum of per-line taxes already calculated above
            $taxAmount   = round(collect($itemsData)->sum('tax_amount'), 2);
            $totalAmount = round($afterDiscount + ($taxInclusive ? 0 : $taxAmount), 2);

            // Fill in amount for single-payment backwards-compat
            if (count($paymentsInput) === 1 && $paymentsInput[0]['amount'] === null) {
                $paymentsInput[0]['amount'] = $totalAmount;
            }

            // Validate total payments >= total (allow rounding +/-0.01)
            // In deposit mode, only the deposit amount needs to be covered.
            $isDeposit       = !empty($validated['is_deposit']);
            $depositAmt      = $isDeposit ? round((float) ($validated['deposit_amount'] ?? 0), 2) : null;
            $shippingAmt     = round((float) ($validated['shipping_amount'] ?? 0), 2);
            $requiredPayment = $isDeposit ? $depositAmt : ($totalAmount + $shippingAmt);
            $totalPayments   = collect($paymentsInput)->sum('amount');

            if ($totalPayments < $requiredPayment - 0.01) {
                DB::rollBack();
                return response()->json([
                    'message' => $isDeposit
                        ? "Payment ({$totalPayments}) does not cover the deposit amount ({$depositAmt})."
                        : "Total payments ({$totalPayments}) do not cover the order total ({$requiredPayment}).",
                ], 422);
            }

            // Validate individual cash payments
            foreach ($paymentsInput as $pmt) {
                if ($pmt['method'] === 'cash') {
                    $cashAmt      = (float) $pmt['amount'];
                    $cashReceived = (float) ($pmt['cash_received'] ?? 0);
                    if ($cashReceived < $cashAmt) {
                        DB::rollBack();
                        return response()->json([
                            'message' => "Cash received ({$cashReceived}) is less than cash payment amount ({$cashAmt}).",
                        ], 422);
                    }
                }
            }

            // -- 3. Order number -----------------------------------------------
            $prefix      = 'POS-' . date('ymd') . '-';
            $orderNumber = $this->generateUniqueOrderNumber($prefix);

            // -- 4. Create order -----------------------------------------------
            // Resolve user_id from an attached known customer
            $customerId   = $validated['customer_id'] ?? null;
            $linkedUserId = null;
            if ($customerId) {
                $customerRecord = \App\Models\Customer::find($customerId);
                $linkedUserId   = $customerRecord?->user_id ?? null;
            }

            // Create new customer record if the frontend sent new_customer data (id === -1)
            if (!$customerId && !empty($validated['new_customer'])) {
                $nc = $validated['new_customer'];
                $newCustomer = \App\Models\Customer::create([
                    'first_name' => $nc['first_name'],
                    'last_name'  => $nc['last_name'] ?? '',   // Customer model's creating() guard also defends against null here - belt-and-suspenders
                    'phone'      => $nc['phone'],
                    'email'      => $nc['email'] ?? null,
                    'created_by' => $user->id,
                ]);
                $customerId = $newCustomer->id;
                // Populate customer name fields from the new record if not already set
                $validated['customer_first_name'] = $validated['customer_first_name'] ?? $nc['first_name'];
                $validated['customer_last_name']  = $validated['customer_last_name']  ?? ($nc['last_name'] ?? null);
                $validated['customer_phone']      = $validated['customer_phone']      ?? $nc['phone'];
                $validated['customer_email']      = $validated['customer_email']      ?? ($nc['email'] ?? null);
            }

            $shippingAmt  = round((float) ($validated['shipping_amount'] ?? 0), 2);
            $isDeposit    = !empty($validated['is_deposit']);
            $depositAmt   = $isDeposit ? round((float) ($validated['deposit_amount'] ?? 0), 2) : null;

            // In deposit mode the payment status is 'deposit' regardless of amount paid
            $paymentStatus = $isDeposit
                ? 'deposit'
                : ($totalPayments >= $totalAmount + $shippingAmt - 0.01 ? 'paid' : 'partial');

            $order = Order::create([
                'order_number'         => $orderNumber,
                'outlet_id'            => $outletId,
                'user_id'              => $linkedUserId,
                'order_type'           => 'pos',
                // POS instant cash/card/mpesa: payment is confirmed but goods
                // still need to be packed/handed over. Use 'confirmed' so staff
                // can set 'completed' after handover. Deposit: 'processing'.
                'status'               => $isDeposit ? 'processing' : 'confirmed',
                'payment_status'       => $paymentStatus,
                'currency_code'        => $currencyCode,
                'customer_country_code' => $customerCountryCode ?: null,
                'is_international'     => $isInternational,
                'subtotal'             => round($itemSubtotal, 2),
                'discount_amount'      => round($cartDiscount, 2),
                // FIX 2: persist raw cart discount type + value for lossless restore
                'cart_discount_type'   => $cartDiscType,
                'cart_discount_value'  => $cartDiscVal,
                'tax_amount'           => $taxAmount,
                'prices_include_tax'   => $taxInclusive,
                'shipping_amount'      => $shippingAmt,
                'total_amount'         => round($afterDiscount + ($taxInclusive ? 0 : $taxAmount) + $shippingAmt, 2),
                // FIX 3: persist customer FK so restore can re-link the record
                'customer_id'          => $customerId,
                'customer_first_name'  => $validated['customer_first_name'] ?? null,
                'customer_last_name'   => $validated['customer_last_name']  ?? null,
                'customer_phone'       => $validated['customer_phone']       ?? null,
                'customer_email'       => $validated['customer_email']       ?? null,
                'deposit_amount'       => $depositAmt,
                'payment_method'       => $validated['payment_method'],
                'notes'                => $validated['notes'] ?? null,
                'completed_at'         => null,  // set explicitly by staff, not by payment
                'created_by'           => $user->id,
            ]);

            // -- 5. Order items + inventory deductions -------------------------
            foreach ($itemsData as $item) {
                OrderItem::create([
                    'order_id'           => $order->id,
                    'product_id'         => $item['product_id'] ?: ($item['variant']?->product_id),
                    'product_variant_id' => $item['variant_id'],
                    'product_name'       => $item['product_name'],
                    'variant_name'       => $item['variant']?->variant_name,
                    'sku'                => $item['variant']?->sku ?? ($item['product_id'] ? Product::find($item['product_id'])?->sku : null),
                    'quantity'           => $item['quantity'],
                    'unit_price'         => $item['unit_price'],
                    // FIX 1: persist raw discount type + value for lossless restore
                    'discount_type'      => $item['discount_type'],
                    'discount_value'     => $item['discount_value'],
                    'discount_amount'    => $item['discount_amount'],
                    'tax_amount'         => $item['tax_amount'],   // Phase 2 — per-product tax
                    'total_price'        => $item['total_price'],
                ]);

                // Deduct stock via the model's helper (logs transaction automatically)
                $item['inventory']->adjustQuantity(
                    -$item['quantity'],
                    'sale',
                    Order::class,
                    $order->id,
                    $user->id
                );
            }

            // -- 6. Payment records - one per split payment -------------------
            $totalCashForRegister   = 0;
            $totalCardForRegister   = 0;
            $totalMpesaForRegister  = 0;
            $totalSalesForRegister  = 0;
            $primaryChange          = 0;

            foreach ($paymentsInput as $pmt) {
                $pmtAmount  = (float) $pmt['amount'];
                $pmtIsCash  = $isCashTypeSale($pmt['method'] ?? '');
                $pmtCashRec = $pmtIsCash ? (float) ($pmt['cash_received'] ?? $pmtAmount) : null;
                $pmtChange  = $pmtIsCash ? max(0, ($pmtCashRec ?? $pmtAmount) - $pmtAmount) : null;
                Payment::create([
                    'order_id'           => $order->id,
                    'amount'             => $pmtAmount,
                    'currency_code'      => $currencyCode,
                    'payment_method'     => $pmt['method'],
                    'status'             => 'paid',
                    'provider_reference' => $pmt['reference'] ?? null,
                    'phone_number'       => $pmt['method'] === 'mpesa'
                        ? ($validated['customer_phone'] ?? null) : null,
                    'cash_received'      => $pmtCashRec,
                    'change_given'       => $pmtChange,
                    'paid_at'            => now(),
                ]);

                // Accumulate for register update
                $totalSalesForRegister += $pmtAmount;
                if ($pmtIsCash) {
                    $totalCashForRegister += $pmtAmount;
                    $primaryChange = $pmtChange ?? 0;
                } elseif ($pmt['method'] === 'card') {
                    $totalCardForRegister += $pmtAmount;
                } elseif ($pmt['method'] === 'mpesa') {
                    $totalMpesaForRegister += $pmtAmount;
                }
            }

            // -- 7. Register update — every sale on an open register updates the
            // aggregates + transaction count; only the cash portion moves
            // expected_cash and the ledger. (Non-cash sales were dropped entirely.)
            if ($register) {
                DB::table('cash_registers')->where('id', $register->id)->update([
                    'total_sales'       => DB::raw('total_sales + ' . $totalSalesForRegister),
                    'total_cash_sales'  => DB::raw('total_cash_sales + ' . $totalCashForRegister),
                    'total_card_sales'  => DB::raw('total_card_sales + ' . $totalCardForRegister),
                    'total_mpesa_sales' => DB::raw('total_mpesa_sales + ' . $totalMpesaForRegister),
                    'transaction_count' => DB::raw('transaction_count + 1'),
                    'expected_cash'     => DB::raw('expected_cash + ' . $totalCashForRegister),
                    'updated_at'        => now(),
                ]);

                // MON-3: per-movement cash-drawer ledger row (cash only).
                if ($totalCashForRegister > 0) {
                    $this->recordCashLedger(
                        $register,
                        'sale',
                        'cash',
                        $totalCashForRegister,
                        (float) $register->expected_cash + $totalCashForRegister,
                        $order->id,
                        $user->id,
                    );
                }
            }

            // -- 8. Production orders for Made-to-Order / backorder items -----
            // For each production_item in the request, create a draft ProductionOrder
            // linked back to this sale. These are confirmed once the customer has paid.
            $raisedProductionOrders = [];
            $productionItems = $validated['production_items'] ?? [];

            foreach ($productionItems as $pi) {
                $prodOrderNumber = 'PO-' . date('ymd') . '-' . strtoupper(Str::random(5));
                while (DB::table('production_orders')->where('order_number', $prodOrderNumber)->exists()) {
                    $prodOrderNumber = 'PO-' . date('ymd') . '-' . strtoupper(Str::random(5));
                }

                // Determine a sensible due date — default 14 days from now
                $dueDate = now()->addDays(14)->toDateString();

                $productionOrderId = DB::table('production_orders')->insertGetId([
                    'order_number'       => $prodOrderNumber,
                    'product_id'         => $pi['product_id'],
                    'product_variant_id' => $pi['variant_id'] ?? null,
                    'quantity'           => $pi['quantity'],
                    'priority'           => 'normal',
                    'status'             => 'draft',
                    'outlet_id'          => $outletId,
                    'customer_order_id'  => $order->id,
                    'is_customer_order'  => true,
                    'due_date'           => $dueDate,
                    'notes'              => "Raised from POS sale {$order->order_number}",
                    'created_by'         => $user->id,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);

                $raisedProductionOrders[] = $prodOrderNumber;

                // Add per-item notes to the production order
                if (!empty($pi['production_notes'])) {
                    DB::table('production_orders')
                        ->where('id', $productionOrderId)
                        ->update(['notes' => $pi['production_notes'], 'updated_at' => now()]);
                }
            }

            DB::commit();

            // Phase 2 — post-commit notifications and audit
            try {
                NotificationService::orderPlaced($order->id, $order->order_number, $outletId);
            } catch (\Exception) {}

            try {
                ActivityLogService::log('pos_sale_created', $order, [
                    'order_number'   => $order->order_number,
                    'outlet_id'      => $outletId,
                    'total_amount'   => $order->total_amount,
                    'currency'       => $order->currency_code,
                    'item_count'     => count($validated['items']),
                    'payment_method' => $paymentsInput[0]['method'] ?? 'cash',
                    'customer_id'    => $validated['customer_id'] ?? null,
                ]);
            } catch (\Exception) {}

            // -- Post-commit: attach proof of payment if provided ---------------
            if ($request->hasFile('proof_of_payment')) {
                $file = $request->file('proof_of_payment');
                $path = $file->store("payment-proofs/{$order->id}", 'private');
                // Find the first non-cash / non-mpesa payment to attach the proof to.
                // Cash-type methods (by code or DB type) never carry a proof file.
                $cashTypeCodes = collect($inputMethodCodes)
                    ->filter(fn ($c) => $isCashTypeSale($c))
                    ->push('cash', 'mpesa')
                    ->unique()
                    ->values()
                    ->toArray();
                $targetPaymentId = DB::table('payments')
                    ->where('order_id', $order->id)
                    ->whereNotIn('payment_method', $cashTypeCodes)
                    ->value('id');
                if ($targetPaymentId) {
                    DB::table('payments')->where('id', $targetPaymentId)->update([
                        'proof_of_payment_path' => $path,
                        'requires_approval'     => true,
                        'approval_status'       => 'pending_review',
                        'status'                => 'pending',
                        'updated_at'            => now(),
                    ]);
                    $order->update(['payment_status' => 'pending', 'status' => 'processing']);
                }
            }

            // Notify admin of every cash / cash-type payment so reconciliation is
            // always immediate — same behaviour as the cash option.
            try {
                $salePayments = $order->fresh(['payments'])->payments->sortByDesc('id')->values();
                collect($paymentsInput)->each(function ($pmt, $i) use ($salePayments, $order, $isCashTypeSale) {
                    $pmtModel = $salePayments->get($i);
                    if (!$pmtModel) return;
                    if ($isCashTypeSale($pmt['method'] ?? '')) {
                        NotificationService::paymentReceived(
                            $pmtModel->id,
                            $pmtModel->payment_number,
                            $order->id,
                            $order->order_number,
                            (float) $pmt['amount'],
                            $order->currency_code,
                            $pmt['method']
                        );
                    }
                });
            } catch (\Exception) {}

            $change = round($primaryChange, 2);

            return response()->json([
                'message'           => 'Sale completed successfully.',
                'order'             => $this->transformSaleOrder($order->load('items')),
                'change'            => $change,
                'production_orders' => $raisedProductionOrders,
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('POS sale failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Failed to complete sale. Please try again.'], 500);
        }
    }

    public function sales(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outlet_id'      => 'required|exists:outlets,id',
            'date'           => 'nullable|date',
            'start_date'     => 'nullable|date',
            'end_date'       => 'nullable|date',
            'search'         => 'nullable|string|max:100',
            'per_page'       => 'nullable|integer|min:1|max:100',
            // When true, returns only orders created by the authenticated user,
            // bypassing the orders.view permission check. Every cashier can always
            // see their own orders from the POS history drawer.
            'my_orders_only' => 'nullable|boolean',
        ]);

        $outletId     = (int) $validated['outlet_id'];
        $myOrdersOnly = !empty($validated['my_orders_only']);
        $user         = $request->user();

        $this->authoriseOutletAccess($user, $outletId);

        $query = Order::with(['items', 'payments'])  // FIX 6: payments now eager-loaded
            ->where('outlet_id', $outletId)
            ->where('order_type', 'pos')
            ->orderBy('created_at', 'desc');

        // When my_orders_only is set, restrict to the authenticated user's own
        // orders regardless of any orders.view permission they may or may not hold.
        if ($myOrdersOnly) {
            $query->where('created_by', $user->id);
        }

        if (!empty($validated['date'])) {
            $query->whereDate('created_at', $validated['date']);
        } elseif (!empty($validated['start_date'])) {
            $query->whereDate('created_at', '>=', $validated['start_date'])
                  ->whereDate('created_at', '<=', $validated['end_date'] ?? now());
        } else {
            $query->whereDate('created_at', today());
        }

        if (!empty($validated['search'])) {
            $s = $validated['search'];
            $query->where(function ($q) use ($s) {
                $q->where('order_number', 'ILIKE', "%{$s}%")
                  ->orWhere('customer_first_name', 'ILIKE', "%{$s}%")
                  ->orWhere('customer_last_name',  'ILIKE', "%{$s}%")
                  ->orWhere('customer_phone',       'ILIKE', "%{$s}%");
            });
        }

        $sales = $query->paginate($validated['per_page'] ?? 50);

        return response()->json([
            'data' => collect($sales->items())->map(fn ($o) => $this->transformSaleOrder($o)),
            'meta' => [
                'total'        => $sales->total(),
                'current_page' => $sales->currentPage(),
                'last_page'    => $sales->lastPage(),
            ],
        ]);
    }

    public function saleDetail(Request $request, int $id): JsonResponse
    {
        $order = Order::with(['items', 'outlet:id,name,address_line1,city,phone'])
            ->where('order_type', 'pos')
            ->findOrFail($id);

        $this->authoriseOutletAccess($request->user(), $order->outlet_id);

        return response()->json(['sale' => $this->transformSaleOrder($order)]);
    }

    public function voidSale(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string|max:500']);

        $order = Order::with('items')->where('order_type', 'pos')->findOrFail($id);
        $this->authoriseOutletAccess($request->user(), $order->outlet_id);

        if ($order->status === 'voided') {
            return response()->json(['message' => 'Order is already voided.'], 422);
        }

        DB::beginTransaction();
        try {
            $order->update([
                'status'      => 'voided',
                'customer_notes' => ($order->customer_notes ? $order->customer_notes . ' | ' : '') . "Void: {$validated['reason']}",
            ]);

            // Capture what the sale ACTUALLY collected, by method, BEFORE voiding
            // the payment rows — so the register is reversed by the real cash
            // taken, not the order total (fixes the drift where a deposit/partial/
            // split cash sale was over-debited on void).
            $cashCodes = DB::table('payment_methods')->where('type', 'cash')
                ->pluck('code')->push('cash')->map(fn ($c) => strtolower($c))->unique();
            $vCash = $vCard = $vMpesa = $vTotal = 0.0;
            foreach ($order->payments()->where('status', 'paid')->get() as $p) {
                $amt = (float) $p->amount;
                $vTotal += $amt;
                $m = strtolower($p->payment_method);
                if ($cashCodes->contains($m))                    { $vCash  += $amt; }
                elseif (in_array($m, ['card', 'card_paystack']))  { $vCard  += $amt; }
                elseif (in_array($m, ['mpesa', 'm-pesa']))        { $vMpesa += $amt; }
            }

            // MON-1: POS void previously left payment rows as 'paid'. Void the
            // settled payments and reconcile payment_status so voided sales stop
            // counting as collected.
            $order->payments()
                ->whereNotIn('status', ['voided', 'refunded'])
                ->update(['status' => 'voided', 'updated_at' => now()]);
            $order->syncPaymentStatus();

            foreach ($order->items as $item) {
                $inventory = InventoryItem::where('product_variant_id', $item->product_variant_id)
                    ->where('outlet_id', $order->outlet_id)
                    ->first();
                if ($inventory) {
                    $inventory->adjustQuantity(
                        $item->quantity,
                        'void_return',
                        Order::class,
                        $order->id,
                        $request->user()->id
                    );
                }
            }

            // Reverse the register by what the sale ACTUALLY collected (drawer row
            // locked), keyed on the real payments rather than the order's
            // payment_method label, and ledger the true cash delta.
            if ($vTotal > 0) {
                // D8: reverse against the drawer that ACTUALLY took the sale
                // (from the ledger); if that shift is closed, the acting cashier's
                // current drawer — not blindly "my latest open register".
                $register = $this->resolveDrawerForReversal($order->id, $request->user(), $order->outlet_id);
                if ($register) {
                    DB::table('cash_registers')->where('id', $register->id)->update([
                        'total_sales'       => DB::raw('GREATEST(0, total_sales - ' . $vTotal . ')'),
                        'total_cash_sales'  => DB::raw('GREATEST(0, total_cash_sales - ' . $vCash . ')'),
                        'total_card_sales'  => DB::raw('GREATEST(0, total_card_sales - ' . $vCard . ')'),
                        'total_mpesa_sales' => DB::raw('GREATEST(0, total_mpesa_sales - ' . $vMpesa . ')'),
                        'transaction_count' => DB::raw('GREATEST(0, transaction_count - 1)'),
                        'expected_cash'     => DB::raw('GREATEST(0, expected_cash - ' . $vCash . ')'),
                        'updated_at'        => now(),
                    ]);

                    // MON-3: ledger the cash reversal (only the cash actually moved).
                    if ($vCash > 0) {
                        $this->recordCashLedger(
                            $register,
                            'void',
                            'cash',
                            $vCash,
                            max(0, (float) $register->expected_cash - $vCash),
                            $order->id,
                            $request->user()->id,
                            'POS void',
                        );
                    }
                }
            }

            DB::commit();

            try {
                ActivityLogService::log('pos_sale_voided', $order, [
                    'order_number'   => $order->order_number,
                    'outlet_id'      => $order->outlet_id,
                    'total_amount'   => $order->total_amount,
                    'reason'         => $validated['reason'],
                    'payment_method' => $order->payment_method,
                ]);
            } catch (\Exception) {}

            return response()->json(['message' => 'Sale voided successfully.']);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('POS void failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to void sale.'], 500);
        }
    }

    public function emailReceipt(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['email' => 'required|email|max:255']);
        $order = Order::with(['items', 'outlet'])->where('order_type', 'pos')->findOrFail($id);

        // TODO: dispatch SendPosReceiptMail::dispatch($order, $validated['email'])
        Log::info('POS receipt email queued', ['order_id' => $id, 'email' => $validated['email']]);

        return response()->json(['message' => 'Receipt sent to ' . $validated['email']]);
    }

    // --- Returns --------------------------------------------------------------

    public function processReturn(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'original_order_id'          => 'required|exists:orders,id',
            'items'                      => 'required|array|min:1',
            'items.*.variant_id'         => 'nullable|exists:product_variants,id',
            'items.*.quantity'           => 'required|integer|min:1',
            'reason'                     => 'required|string|max:500',
            'refund_method'              => 'required|in:cash,mpesa,store_credit,card',
        ]);

        $order = Order::with('items')->findOrFail($validated['original_order_id']);

        if ($order->order_type !== 'pos') {
            return response()->json(['message' => 'Only POS orders can be returned via this endpoint.'], 422);
        }

        $this->authoriseOutletAccess($request->user(), $order->outlet_id);

        DB::beginTransaction();
        try {
            $refundTotal = 0;
            $returnItems = [];

            foreach ($validated['items'] as $req) {
                $orderItem = $order->items->firstWhere('product_variant_id', $req['variant_id']);

                if (!$orderItem) {
                    DB::rollBack();
                    return response()->json(['message' => "Variant #{$req['variant_id']} not found in this order."], 422);
                }

                // Check how much has already been returned for this item
                $alreadyReturned = DB::table('return_items')
                    ->whereIn('return_id', fn ($q) => $q->select('id')->from('order_returns')->where('order_id', $order->id))
                    ->where('order_item_id', $orderItem->id)
                    ->sum('quantity');

                $maxReturnable = $orderItem->quantity - $alreadyReturned;
                if ($req['quantity'] > $maxReturnable) {
                    DB::rollBack();
                    return response()->json([
                        'message' => "Cannot return {$req['quantity']} - only {$maxReturnable} returnable for this item.",
                    ], 422);
                }

                $lineRefund   = $orderItem->unit_price * $req['quantity'];
                $refundTotal += $lineRefund;

                $returnItems[] = [
                    'order_item_id' => $orderItem->id,
                    'quantity'      => $req['quantity'],
                    'reason'        => $validated['reason'],
                    'restock'       => true,
                ];

                // Restore inventory
                $inventory = InventoryItem::where('product_variant_id', $req['variant_id'])
                    ->where('outlet_id', $order->outlet_id)
                    ->first();
                $inventory?->adjustQuantity(
                    $req['quantity'],
                    'return',
                    Order::class,
                    $order->id,
                    $request->user()->id
                );
            }

            // Bound the refund to what was ACTUALLY collected on this order, net
            // of prior refunds. The line total above is unit_price × qty, which
            // ignores discounts and tax and could pay out more than the customer
            // ever paid — this cap closes that cash leak.
            $collected    = (float) $order->payments()->where('status', 'paid')->sum('amount');
            $priorRefunds = (float) DB::table('order_returns')
                ->where('order_id', $order->id)->where('status', 'completed')->sum('refund_amount');
            $refundTotal  = min($refundTotal, max(0, $collected - $priorRefunds));

            // Create return record
            $orderReturn = OrderReturn::create([
                'order_id'      => $order->id,
                'status'        => 'completed',
                'return_reason' => $validated['reason'],
                'refund_amount' => round($refundTotal, 2),
                'refund_method' => $validated['refund_method'],
                'created_by'    => $request->user()->id,
                'approved_by'   => $request->user()->id,
                'approved_at'   => now(),
                'refunded_at'   => now(),
            ]);

            // Create return_items rows
            foreach ($returnItems as $ri) {
                DB::table('return_items')->insert([
                    'return_id'     => $orderReturn->id,
                    'order_item_id' => $ri['order_item_id'],
                    'quantity'      => $ri['quantity'],
                    'reason'        => $ri['reason'],
                    'restock'       => $ri['restock'],
                    'created_at'    => now(),
                ]);
            }

            // Deduct the cash refund from the drawer that took the sale (D8) — or,
            // if that shift is closed, the acting cashier's current drawer (the
            // cash is paid out of the till in front of them). Drawer locked.
            if ($validated['refund_method'] === 'cash' && $refundTotal > 0) {
                $register = $this->resolveDrawerForReversal($order->id, $request->user(), $order->outlet_id);
                if ($register) {
                    // Reject rather than silently clamp if the drawer can't cover it.
                    if ($refundTotal > (float) $register->expected_cash) {
                        DB::rollBack();
                        return response()->json(['message' => 'Insufficient cash in the register to make this refund.'], 422);
                    }
                    DB::table('cash_registers')->where('id', $register->id)->update([
                        'total_refunds' => DB::raw('total_refunds + ' . $refundTotal),
                        'expected_cash' => DB::raw('GREATEST(0, expected_cash - ' . $refundTotal . ')'),
                        'updated_at'    => now(),
                    ]);

                    // MON-3: ledger the cash refund.
                    $this->recordCashLedger(
                        $register,
                        'refund',
                        'cash',
                        (float) $refundTotal,
                        max(0, (float) $register->expected_cash - (float) $refundTotal),
                        $order->id,
                        $request->user()->id,
                        'POS return',
                    );
                }
            }

            DB::commit();

            try {
                ActivityLogService::log('pos_return_processed', $order, [
                    'return_id'     => $orderReturn->id,
                    'return_number' => $orderReturn->return_number,
                    'outlet_id'     => $order->outlet_id,
                    'refund_amount' => round($refundTotal, 2),
                    'refund_method' => $validated['refund_method'],
                    'reason'        => $validated['reason'],
                    'items_count'   => count($validated['items']),
                ]);
            } catch (\Exception) {}

            return response()->json([
                'message'       => 'Return processed successfully.',
                'return_number' => $orderReturn->return_number,
                'refund_amount' => $refundTotal,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('POS return failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to process return.'], 500);
        }
    }

    public function returns(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            'date'      => 'nullable|date',
        ]);

        $returns = OrderReturn::with(['order:id,order_number,outlet_id', 'createdBy:id,first_name,last_name'])
            ->whereHas('order', fn ($q) => $q->where('outlet_id', $validated['outlet_id']))
            ->when($validated['date'] ?? null, fn ($q) => $q->whereDate('created_at', $validated['date']))
            ->latest()
            ->paginate(30);

        return response()->json($returns);
    }

    // --- Shipping Methods (for POS checkout picker) ---------------------------

    public function shippingMethods(Request $request): JsonResponse
    {
        $methods = DB::table('shipping_methods')
            ->leftJoin('shipping_zones', 'shipping_methods.shipping_zone_id', '=', 'shipping_zones.id')
            ->where('shipping_methods.is_active', true)
            ->select(
                'shipping_methods.id',
                'shipping_methods.name',
                'shipping_methods.description',
                'shipping_methods.delivery_time',
                'shipping_methods.cost_type',
                'shipping_methods.flat_rate',
                'shipping_methods.min_order_amount',
                'shipping_methods.sort_order',
                'shipping_zones.name as zone_name'
            )
            ->orderBy('shipping_methods.sort_order')
            ->orderBy('shipping_methods.name')
            ->get();

        return response()->json(['data' => $methods]);
    }

    public function searchCustomers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q'        => 'required|string|min:1|max:100',
            'per_page' => 'nullable|integer|min:1|max:20',
        ]);

        $q = trim($validated['q']);

        $customers = \App\Models\Customer::with('user:id,first_name,last_name,email,phone')
            ->where(function ($query) use ($q) {
                $query->where('phone', 'ILIKE', "%{$q}%")
                    ->orWhereHas('user', function ($uq) use ($q) {
                        $uq->where('first_name', 'ILIKE', "%{$q}%")
                           ->orWhere('last_name',  'ILIKE', "%{$q}%")
                           ->orWhere('email',       'ILIKE', "%{$q}%")
                           ->orWhere('phone',       'ILIKE', "%{$q}%")
                           ->orWhereRaw("CONCAT(first_name, ' ', last_name) ILIKE ?", ["%{$q}%"]);
                    });
            })
            ->limit($validated['per_page'] ?? 8)
            ->get()
            ->map(fn ($c) => [
                'id'    => $c->id,
                'name'  => trim(($c->user?->first_name ?? '') . ' ' . ($c->user?->last_name ?? ''))
                           ?: ($c->phone ?? "Customer #{$c->id}"),
                'phone' => $c->phone ?? $c->user?->phone,
                'email' => $c->user?->email,
            ]);

        return response()->json(['data' => $customers]);
    }

    // --- User End-of-Day Report -----------------------------------------------

    /**
     * GET /admin/pos/reports/user-eod?outlet_id=X&date=YYYY-MM-DD
     *
     * Returns this cashier's personal EoD summary for the given date:
     * - All POS orders they created at that outlet on that date
     * - Per-order: customer name, items, total, amount paid, outstanding balance
     * - Any existing EoD report (order notes + day sentiments) if already submitted
     */
    public function getUserEodReport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            'date'      => 'nullable|date_format:Y-m-d',
        ]);

        $outletId = (int) $validated['outlet_id'];
        $date     = $validated['date'] ?? today()->toDateString();
        $user     = $request->user();

        $this->authoriseOutletAccess($user, $outletId);

        // Find this user's register that covers the given date
        $register = CashRegister::where('outlet_id', $outletId)
            ->where('opened_by', $user->id)
            ->whereDate('opened_at', $date)
            ->latest('opened_at')
            ->first();

        // Fallback: register opened on a prior day but still active on this date
        if (!$register) {
            $register = CashRegister::where('outlet_id', $outletId)
                ->where('opened_by', $user->id)
                ->whereDate('opened_at', '<=', $date)
                ->where(function ($q) use ($date) {
                    $q->whereNull('closed_at')
                      ->orWhereDate('closed_at', '>=', $date);
                })
                ->latest('opened_at')
                ->first();
        }

        // Fetch all orders created by this user on this date at this outlet
        $orders = Order::with(['items', 'payments'])
            ->where('outlet_id', $outletId)
            ->where('order_type', 'pos')
            ->where('created_by', $user->id)
            ->whereDate('created_at', $date)
            ->whereNotIn('status', ['voided', 'cancelled'])
            ->orderBy('created_at')
            ->get();

        $totalSales   = 0.0;
        $totalPaid    = 0.0;
        $totalBalance = 0.0;

        $ordersData = $orders->map(function (Order $order) use (&$totalSales, &$totalPaid, &$totalBalance) {
            $paid = $order->payments
                ->whereIn('status', ['completed', 'approved', 'paid'])
                ->sum('amount');

            $balance = max(0, $order->total_amount - $paid);

            $totalSales   += $order->total_amount;
            $totalPaid    += $paid;
            $totalBalance += $balance;

            $customerName = trim(
                ($order->customer_first_name ?? '') . ' ' . ($order->customer_last_name ?? '')
            ) ?: null;

            if (!$customerName && $order->customer_id) {
                $customer = DB::table('customers')
                    ->where('id', $order->customer_id)
                    ->select(DB::raw("TRIM(CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,''))) as name"))
                    ->first();
                $customerName = $customer?->name ?: null;
            }

            return [
                'id'            => $order->id,
                'order_number'  => $order->order_number,
                'customer_name' => $customerName ?: 'Walk-in',
                'items'         => $order->items->map(fn ($i) => [
                    'product_name' => $i->product_name,
                    'variant_name' => $i->variant_name,
                    'quantity'     => (int) $i->quantity,
                    'unit_price'   => (float) $i->unit_price,
                    'total_price'  => (float) $i->total_price,
                ])->values(),
                'total_amount'   => round((float) $order->total_amount, 2),
                'amount_paid'    => round($paid, 2),
                'balance'        => round($balance, 2),
                'payment_status' => $order->payment_status,
                'created_at'     => $order->created_at->toIso8601String(),
                'eod_note'       => null, // merged from report below
            ];
        })->values()->toArray();

        // Fetch existing EoD report (if submitted previously)
        $existingReport = null;
        if ($register) {
            $report = DB::table('cash_register_eod_reports')
                ->where('register_id', $register->id)
                ->where('user_id', $user->id)
                ->whereDate('report_date', $date)
                ->first();

            if ($report) {
                $orderNotes = json_decode($report->order_notes ?? '{}', true) ?: [];

                // Merge per-order notes back into the orders array
                foreach ($ordersData as &$o) {
                    $o['eod_note'] = $orderNotes[strval($o['id'])] ?? null;
                }
                unset($o);

                $existingReport = [
                    'id'           => $report->id,
                    'sentiments'   => $report->sentiments ?? '',
                    'order_notes'  => $orderNotes,
                    'submitted_at' => $report->submitted_at,
                ];
            }
        }

        $userName = trim("{$user->first_name} {$user->last_name}") ?: $user->email;

        return response()->json([
            'summary' => [
                'date'            => $date,
                'register_id'     => $register?->id,
                'user_name'       => $userName,
                'outlet_name'     => Outlet::find($outletId)?->name,
                'order_count'     => count($ordersData),
                'total_sales'     => round($totalSales, 2),
                'total_paid'      => round($totalPaid, 2),
                'total_balance'   => round($totalBalance, 2),
                'orders'          => $ordersData,
                'existing_report' => $existingReport,
            ],
        ]);
    }

    /**
     * POST /admin/pos/reports/user-eod
     *
     * Saves (or updates) the cashier's personal EoD report for today.
     * Sets submitted_at, which unblocks the closeRegister endpoint.
     * May be called multiple times — subsequent calls update the existing report.
     */
    public function saveUserEodReport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outlet_id'     => 'required|exists:outlets,id',
            'date'          => 'required|date_format:Y-m-d',
            'register_id'   => 'required|exists:cash_registers,id',
            'order_notes'   => 'nullable|array',
            'order_notes.*' => 'nullable|string|max:1000',
            'sentiments'    => 'nullable|string|max:20000',
        ]);

        $outletId   = (int) $validated['outlet_id'];
        $registerId = (int) $validated['register_id'];
        $date       = $validated['date'];
        $user       = $request->user();

        $this->authoriseOutletAccess($user, $outletId);

        // Verify the register belongs to this user
        CashRegister::where('id', $registerId)
            ->where('opened_by', $user->id)
            ->firstOrFail();

        // Only keep non-empty notes
        $cleanNotes = collect($validated['order_notes'] ?? [])
            ->filter(fn ($v) => is_string($v) && trim($v) !== '')
            ->toArray();

        $now = now();

        $existing = DB::table('cash_register_eod_reports')
            ->where('register_id', $registerId)
            ->where('user_id', $user->id)
            ->whereDate('report_date', $date)
            ->first();

        if ($existing) {
            DB::table('cash_register_eod_reports')
                ->where('id', $existing->id)
                ->update([
                    'order_notes'  => json_encode($cleanNotes),
                    'sentiments'   => $validated['sentiments'] ?? null,
                    'submitted_at' => $now,
                    'updated_at'   => $now,
                ]);
            $reportId = $existing->id;
        } else {
            $reportId = DB::table('cash_register_eod_reports')->insertGetId([
                'register_id'  => $registerId,
                'user_id'      => $user->id,
                'outlet_id'    => $outletId,
                'report_date'  => $date,
                'order_notes'  => json_encode($cleanNotes),
                'sentiments'   => $validated['sentiments'] ?? null,
                'submitted_at' => $now,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }

        try {
            ActivityLogService::log('eod_report_submitted', null, [
                'register_id'    => $registerId,
                'outlet_id'      => $outletId,
                'date'           => $date,
                'note_count'     => count($cleanNotes),
                'has_sentiments' => !empty($validated['sentiments']),
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message'   => 'End of Day report saved successfully.',
            'report_id' => $reportId,
        ]);
    }

    /**
     * GET /admin/pos/reports/eod-admin
     *
     * Admin listing of all submitted EoD reports across outlets and users.
     * Supports date range, outlet, and user filters.
     * Also returns the set of users who have submitted within the period
     * (for populating the cashier filter dropdown).
     */
    public function adminListEodReports(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from'      => 'nullable|date_format:Y-m-d',
            'to'        => 'nullable|date_format:Y-m-d',
            'outlet_id' => 'nullable|exists:outlets,id',
            'user_id'   => 'nullable|exists:users,id',
            // FIX: pagination params, same pattern as the other paginated
            // list endpoints (StockTransfersPage, PurchaseOrdersPage, etc).
            'page'      => 'nullable|integer|min:1',
            'per_page'  => 'nullable|integer|min:1|max:100',
        ]);

        $from     = $validated['from'] ?? now()->subDays(30)->toDateString();
        $to       = $validated['to']   ?? today()->toDateString();
        $outletId = isset($validated['outlet_id']) ? (int) $validated['outlet_id'] : null;
        $userId   = isset($validated['user_id'])   ? (int) $validated['user_id']   : null;
        // FIX: page/per_page, same defaults as the other list endpoints
        $page     = (int) ($validated['page'] ?? 1);
        $perPage  = (int) ($validated['per_page'] ?? 20);

        $query = DB::table('cash_register_eod_reports as r')
            ->join('users as u',   'u.id', '=', 'r.user_id')
            ->join('outlets as o', 'o.id', '=', 'r.outlet_id')
            ->whereBetween('r.report_date', [$from, $to])
            ->whereNotNull('r.submitted_at')
            ->when($outletId, fn ($q) => $q->where('r.outlet_id', $outletId))
            ->when($userId,   fn ($q) => $q->where('r.user_id', $userId));

        // FIX: paginate the raw query BEFORE the expensive per-row KPI mapping
        // below. Each row's KPI calculation issues its own DB query (orders +
        // payments), so pagination must happen here - paginating the post-
        // mapped collection in PHP would still fetch and compute KPIs for
        // every row in the date range before throwing most of them away,
        // saving nothing on every request.
        $paginated = (clone $query)
            ->select([
                'r.id',
                'r.report_date',
                'r.submitted_at',
                'r.outlet_id',
                'o.name as outlet_name',
                'r.user_id',
                DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) as user_name"),
                'r.order_notes',
                'r.sentiments',
            ])
            ->orderByDesc('r.report_date')
            ->orderBy('u.first_name')
            ->paginate($perPage, ['*'], 'page', $page);

        $rows = collect($paginated->items());

        // For each report, compute order-level KPIs by joining back to orders
        $reportData = $rows->map(function ($row) {
            $orderNotes = json_decode($row->order_notes ?? '{}', true) ?: [];

            // Fetch orders for this user+outlet+date
            $orders = DB::table('orders as ord')
                ->leftJoin('payments as p', function ($j) {
                    $j->on('p.order_id', '=', 'ord.id')
                      ->whereIn('p.status', ['completed', 'approved', 'paid']);
                })
                ->where('ord.outlet_id', $row->outlet_id)
                ->where('ord.created_by', $row->user_id)
                ->whereDate('ord.created_at', $row->report_date)
                ->whereNotIn('ord.status', ['voided', 'cancelled'])
                ->where('ord.order_type', 'pos')
                ->groupBy('ord.id', 'ord.total_amount')
                ->select([
                    'ord.id',
                    DB::raw('COALESCE(SUM(p.amount), 0) as amount_paid'),
                    'ord.total_amount',
                ])
                ->get();

            $totalSales   = $orders->sum('total_amount');
            $totalPaid    = $orders->sum('amount_paid');
            $totalBalance = max(0, $totalSales - $totalPaid);

            return [
                'id'             => $row->id,
                'report_date'    => $row->report_date,
                'submitted_at'   => $row->submitted_at,
                'outlet_id'      => $row->outlet_id,
                'outlet_name'    => $row->outlet_name,
                'user_id'        => $row->user_id,
                'user_name'      => trim($row->user_name) ?: 'Unknown',
                'order_count'    => $orders->count(),
                'total_sales'    => round((float) $totalSales, 2),
                'total_paid'     => round((float) $totalPaid, 2),
                'total_balance'  => round((float) $totalBalance, 2),
                'has_sentiments' => !empty(trim($row->sentiments ?? '')),
                'note_count'     => count(array_filter($orderNotes, fn ($v) => is_string($v) && trim($v) !== '')),
            ];
        });

        // Distinct users for the filter dropdown - intentionally NOT paginated;
        // this populates a <select> and must reflect the full filtered range.
        $users = DB::table('cash_register_eod_reports as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->whereBetween('r.report_date', [$from, $to])
            ->whereNotNull('r.submitted_at')
            ->when($outletId, fn ($q) => $q->where('r.outlet_id', $outletId))
            ->distinct()
            ->select([
                'r.user_id as id',
                DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) as name"),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn ($u) => ['id' => $u->id, 'name' => trim($u->name) ?: 'Unknown']);

        return response()->json([
            'data'  => $reportData->values(),
            // FIX: meta block, same shape as every other paginated list endpoint
            'meta'  => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'from'         => $paginated->firstItem(),
                'to'           => $paginated->lastItem(),
                'total'        => $paginated->total(),
            ],
            'users' => $users->values(),
        ]);
    }

    /**
     * GET /admin/pos/reports/eod-admin/{id}
     *
     * Full detail for a single submitted EoD report, including the HTML
     * sentiments, per-order notes, and order-level reconciliation data.
     */
    public function adminGetEodReport(Request $request, int $id): JsonResponse
    {
        $row = DB::table('cash_register_eod_reports as r')
            ->join('users as u',   'u.id', '=', 'r.user_id')
            ->join('outlets as o', 'o.id', '=', 'r.outlet_id')
            ->where('r.id', $id)
            ->select([
                'r.*',
                'o.name as outlet_name',
                DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) as user_name"),
            ])
            ->first();

        if (!$row) {
            return response()->json(['message' => 'Report not found.'], 404);
        }

        $orderNotes = json_decode($row->order_notes ?? '{}', true) ?: [];

        // Fetch orders with items and payments
        $orders = Order::with(['items', 'payments'])
            ->where('outlet_id', $row->outlet_id)
            ->where('created_by', $row->user_id)
            ->whereDate('created_at', $row->report_date)
            ->whereNotIn('status', ['voided', 'cancelled'])
            ->where('order_type', 'pos')
            ->orderBy('created_at')
            ->get();

        $totalSales   = 0.0;
        $totalPaid    = 0.0;
        $totalBalance = 0.0;

        $ordersData = $orders->map(function (Order $order) use (&$totalSales, &$totalPaid, &$totalBalance, $orderNotes) {
            $paid = $order->payments
                ->whereIn('status', ['completed', 'approved', 'paid'])
                ->sum('amount');

            $balance = max(0, $order->total_amount - $paid);
            $totalSales   += $order->total_amount;
            $totalPaid    += $paid;
            $totalBalance += $balance;

            $customerName = trim(
                ($order->customer_first_name ?? '') . ' ' . ($order->customer_last_name ?? '')
            ) ?: 'Walk-in';

            return [
                'id'             => $order->id,
                'order_number'   => $order->order_number,
                'customer_name'  => $customerName,
                'total_amount'   => round((float) $order->total_amount, 2),
                'amount_paid'    => round($paid, 2),
                'balance'        => round($balance, 2),
                'payment_status' => $order->payment_status,
                'eod_note'       => $orderNotes[strval($order->id)] ?? null,
            ];
        })->values();

        return response()->json([
            'report' => [
                'id'             => $row->id,
                'report_date'    => $row->report_date,
                'submitted_at'   => $row->submitted_at,
                'outlet_id'      => $row->outlet_id,
                'outlet_name'    => $row->outlet_name,
                'user_id'        => $row->user_id,
                'user_name'      => trim($row->user_name) ?: 'Unknown',
                'sentiments'     => $row->sentiments ?? '',
                'order_notes'    => $orderNotes,
                'order_count'    => $ordersData->count(),
                'total_sales'    => round($totalSales, 2),
                'total_paid'     => round($totalPaid, 2),
                'total_balance'  => round($totalBalance, 2),
                'orders'         => $ordersData,
            ],
        ]);
    }

    /**
     * GET /admin/pos/reports/eod-settings
     *
     * Load the EoD report delivery configuration from system_settings.
     */
    public function getEodDeliverySettings(Request $request): JsonResponse
    {
        $raw = DB::table('system_settings')
            ->where('key', 'eod_delivery')
            ->value('value');

        $defaults = [
            'email_enabled'    => false,
            'email_recipients' => '',
            'email_frequency'  => 'daily',
            'email_time'       => '21:00',
            'slack_enabled'    => false,
            'slack_webhook'    => '',
            'slack_frequency'  => 'daily',
            'slack_time'       => '21:00',
            'outlet_ids'       => [],
        ];

        $settings = $raw ? array_merge($defaults, json_decode($raw, true) ?: []) : $defaults;

        return response()->json(['settings' => $settings]);
    }

    /**
     * POST /admin/pos/reports/eod-settings
     *
     * Persist EoD delivery configuration to system_settings.
     */
    public function saveEodDeliverySettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email_enabled'    => 'boolean',
            'email_recipients' => 'nullable|string|max:2000',
            'email_frequency'  => 'in:daily,weekly,off',
            'email_time'       => ['nullable', 'regex:/^\d{2}:\d{2}$/'],
            'slack_enabled'    => 'boolean',
            'slack_webhook'    => 'nullable|url|max:500',
            'slack_frequency'  => 'in:daily,weekly,off',
            'slack_time'       => ['nullable', 'regex:/^\d{2}:\d{2}$/'],
            'outlet_ids'       => 'nullable|array',
            'outlet_ids.*'     => 'integer|exists:outlets,id',
        ]);

        $payload = json_encode($validated);

        DB::table('system_settings')->upsert(
            [
                'key'        => 'eod_delivery',
                'value'      => $payload,
                'updated_at' => now(),
            ],
            ['key'],
            ['value', 'updated_at'],
        );

        try {
            ActivityLogService::log('eod_delivery_settings_updated', null, [
                'email_enabled' => $validated['email_enabled'] ?? false,
                'slack_enabled' => $validated['slack_enabled'] ?? false,
            ]);
        } catch (\Exception) {}

        return response()->json(['message' => 'EoD delivery settings saved.']);
    }

    /**
     * POST /admin/pos/reports/eod-settings/test
     *
     * Fire a one-off test delivery using the currently saved settings.
     * Sends today's (or most recent) consolidated EoD report.
     */
    public function testEodDelivery(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel'          => 'required|in:email,slack',
            // Optional overrides from the live form — allows "Send test" to work
            // without requiring a prior "Save Settings" click.
            'email_recipients' => 'nullable|string|max:2000',
            'slack_webhook'    => 'nullable|url|max:500',
        ]);

        $raw = DB::table('system_settings')
            ->where('key', 'eod_delivery')
            ->value('value');

        $settings = $raw ? (json_decode($raw, true) ?: []) : [];

        if ($validated['channel'] === 'email') {
            // Live form value takes precedence; fall back to saved DB value
            $rawRecipients = $validated['email_recipients']
                ?? $settings['email_recipients']
                ?? '';

            $recipients = array_filter(
                array_map('trim', explode(',', $rawRecipients)),
                fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)
            );
            if (empty($recipients)) {
                return response()->json(['message' => 'No valid email recipients configured.'], 422);
            }
            // Dispatch the mailable via a queued job so the response is instant
            dispatch(new \App\Jobs\SendEodReportEmail(
                array_values($recipients),
                today()->toDateString(),
                $settings['outlet_ids'] ?? []
            ))->onQueue('default');
        } else {
            // Live form value takes precedence; fall back to saved DB value
            $webhook = trim($validated['slack_webhook'] ?? $settings['slack_webhook'] ?? '');
            if (empty($webhook)) {
                return response()->json(['message' => 'No Slack webhook URL configured.'], 422);
            }
            dispatch(new \App\Jobs\SendEodReportSlack(
                $webhook,
                today()->toDateString(),
                $settings['outlet_ids'] ?? []
            ))->onQueue('default');
        }

        return response()->json(['message' => 'Test delivery queued.']);
    }

    public function dailySummary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            'date'      => 'nullable|date',
        ]);

        $outletId = (int) $validated['outlet_id'];
        $date     = $validated['date'] ?? today()->toDateString();

        $this->authoriseOutletAccess($request->user(), $outletId);

        $ttl     = ($date < today()->toDateString()) ? 3600 : 60;
        $summary = Cache::remember("pos_daily_{$outletId}_{$date}", $ttl, function () use ($outletId, $date) {

            $sales     = Order::where('outlet_id', $outletId)
                ->where('order_type', 'pos')
                ->whereDate('created_at', $date)
                ->get();

            $completed  = $sales->whereNotIn('status', ['voided', 'cancelled']);
            $totalSales = $completed->sum('total_amount');
            $count      = $completed->count();

            $returns = OrderReturn::whereHas('order', fn ($q) =>
                    $q->where('outlet_id', $outletId)
                )->whereDate('created_at', $date)->sum('refund_amount');

            // Hourly breakdown
            $hourly = $completed
                ->groupBy(fn ($s) => (int) $s->created_at->format('H'))
                ->map(fn ($group, $hour) => [
                    'hour'         => $hour,
                    'sales'        => round($group->sum('total_amount'), 2),
                    'transactions' => $group->count(),
                ])
                ->values()->toArray();

            // Top products
            $topProducts = DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->where('orders.outlet_id', $outletId)
                ->where('orders.order_type', 'pos')
                ->whereDate('orders.created_at', $date)
                ->whereNotIn('orders.status', ['voided', 'cancelled'])
                ->selectRaw('order_items.product_name, SUM(order_items.quantity) as qty, SUM(order_items.total_price) as revenue')
                ->groupBy('order_items.product_name')
                ->orderByDesc('revenue')
                ->limit(10)
                ->get()
                ->map(fn ($r) => ['name' => $r->product_name, 'qty' => (int) $r->qty, 'revenue' => round($r->revenue, 2)])
                ->toArray();

            return [
                'date'               => $date,
                'total_sales'        => round($totalSales, 2),
                'total_transactions' => $count,
                'total_returns'      => round((float) $returns, 2),
                'net_sales'          => round($totalSales - (float) $returns, 2),
                'cash_sales'         => round($completed->where('payment_method', 'cash')->sum('total_amount'), 2),
                'mpesa_sales'        => round($completed->where('payment_method', 'mpesa')->sum('total_amount'), 2),
                'card_sales'         => round($completed->where('payment_method', 'card')->sum('total_amount'), 2),
                'other_sales'        => round($completed->whereNotIn('payment_method', ['cash', 'mpesa', 'card'])->sum('total_amount'), 2),
                'average_transaction'=> $count > 0 ? round($totalSales / $count, 2) : 0,
                'hourly_breakdown'   => $hourly,
                'top_products'       => $topProducts,
            ];
        });

        return response()->json(['summary' => $summary]);
    }

    // --- Private helpers ------------------------------------------------------

    private function isAdminUser($user): bool
    {
        return $user->isSuperAdmin()
            || $user->hasRole('super_admin')
            || $user->hasRole('admin');
    }

    /**
     * MON-3: append a per-movement row to the cash-register ledger
     * (`cash_register_transactions`), which POS previously never wrote — the
     * register only carried running aggregate totals with no auditable trail.
     * `balance_after` is the drawer's expected_cash after this movement.
     */
    /**
     * D8: resolve which drawer a void/refund for this order should hit. Prefer
     * the register that ACTUALLY recorded the sale (from the cash ledger), so a
     * void/refund by a different cashier reverses the right till. If that shift
     * is already closed, fall back to the acting cashier's current open drawer —
     * the cash is paid out of the till in front of them. Returned register is
     * locked for update; null when no suitable open drawer exists.
     */
    private function resolveDrawerForReversal(int $orderId, $user, int $outletId): ?CashRegister
    {
        $originId = DB::table('cash_register_transactions')
            ->where('order_id', $orderId)
            ->where('transaction_type', 'sale')
            ->orderBy('id')
            ->value('cash_register_id');

        if ($originId) {
            $origin = CashRegister::whereKey($originId)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();
            if ($origin) {
                return $origin;
            }
        }

        return CashRegister::where('outlet_id', $outletId)
            ->where('opened_by', $user->id)
            ->where('status', 'open')
            ->latest('opened_at')
            ->lockForUpdate()
            ->first();
    }

    private function recordCashLedger(
        CashRegister $register,
        string $type,
        ?string $method,
        float $amount,
        float $balanceAfter,
        ?int $orderId,
        int $userId,
        ?string $notes = null,
    ): void {
        CashRegisterTransaction::create([
            'cash_register_id' => $register->id,
            'transaction_type' => $type,
            'payment_method'   => $method,
            'amount'           => round($amount, 2),
            'balance_after'    => round($balanceAfter, 2),
            'order_id'         => $orderId,
            'notes'            => $notes,
            'created_by'       => $userId,
        ]);
    }

    private function authoriseOutletAccess($user, int $outletId): void
    {
        if ($this->isAdminUser($user)) {
            return;
        }
        $assigned = $user->outlets()->pluck('outlets.id');
        if ($assigned->isNotEmpty() && !$assigned->contains($outletId)) {
            abort(403, 'You do not have access to this outlet.');
        }
    }


    /**
     * Resolve the currency code for an outlet.
     * The outlets table does not have a currency_code column;
     * we derive it from country_code instead.
     */
    private function resolveCurrency(Outlet $outlet): string
    {
        return match (strtoupper($outlet->country_code ?? '')) {
            'USA', 'US'  => 'USD',
            'GBR', 'GB'  => 'GBP',
            'EUR'        => 'EUR',
            default      => 'KES',   // Kenya default
        };
    }

    private function generateUniqueOrderNumber(string $prefix): string
    {
        do {
            $number = $prefix . strtoupper(Str::random(5));
        } while (Order::where('order_number', $number)->exists());
        return $number;
    }

    private function transformProduct(Product $product, int $outletId, string $currency = 'KES'): array
    {
        $translation = $product->translations->firstWhere('language_code', 'en')
            ?? $product->translations->first();
        $primaryImage = $product->images->first();

        $measurements = $product->measurements ?? [];

        // Load exchange rates once — used for currency conversion fallback.
        $rateMap = DB::table('currencies')
            ->where('is_active', true)
            ->get(['code', 'exchange_rate', 'is_base'])
            ->keyBy('code')
            ->map(fn ($r) => ['rate' => (float) $r->exchange_rate, 'is_base' => (bool) $r->is_base])
            ->toArray();

        return [
            'id'           => $product->id,
            'name'         => $translation?->name ?? $product->sku,
            'sku'          => $product->sku,
            'is_producible'=> (bool) $product->is_producible,
            'measurements' => $measurements,
            'category'     => $product->category
                ? ['id' => $product->category->id, 'name' => $product->category->name_en]
                : null,
            'image_url' => $primaryImage?->thumbnail_url ?? $primaryImage?->image_url,
            // ── Simple product fallback ──────────────────────────────────────────
            // If the product has no variants (product_type = 'simple'), synthesise
            // a single virtual variant from the product-level price and inventory
            // so the POS frontend always has at least one variant to render.
            'variants'  => $product->variants->isEmpty()
                ? (function () use ($product, $outletId, $currency, $rateMap): \Illuminate\Support\Collection {
                    // Resolve product-level inventory
                    $candidates = collect([
                        $product->inventoryItems->first(),
                        $product->warehouseInventoryItems->first(),
                    ])->filter();
                    $inventory  = $candidates->sortByDesc(fn ($i) => $i->quantity_available)->first();
                    $available  = $inventory ? $inventory->quantity_available : 0;

                    // Resolve product-level price
                    $priceRow = $product->prices->firstWhere('currency_code', $currency)
                        ?? $product->prices->first(
                            fn ($p) => isset($rateMap[$p->currency_code]) && $rateMap[$p->currency_code]['is_base']
                        )
                        ?? $product->prices->first();

                    if ($priceRow && $priceRow->currency_code !== $currency) {
                        $fromRate     = $rateMap[$priceRow->currency_code]['rate'] ?? 1;
                        $toRate       = $rateMap[$currency]['rate']                ?? 1;
                        $regularPrice = $fromRate > 0 ? round(((float) $priceRow->regular_price / $fromRate) * $toRate, 2) : (float) $priceRow->regular_price;
                        $salePrice    = $priceRow->sale_price && $fromRate > 0
                            ? round(((float) $priceRow->sale_price / $fromRate) * $toRate, 2)
                            : null;
                    } else {
                        $regularPrice = $priceRow ? (float) $priceRow->regular_price : 0.0;
                        $salePrice    = $priceRow?->sale_price ? (float) $priceRow->sale_price : null;
                    }

                    $taxRateDecimal = TaxCalculationService::rateForProduct($product->id);

                    return collect([[
                        'id'           => null,  // virtual — no real variant ID for simple products
                        'sku'          => $product->sku,
                        'variant_name' => $product->translations->first()?->name ?? $product->sku,
                        'attributes'   => [],
                        'price'        => $regularPrice,
                        'sale_price'   => $salePrice,
                        'currency'     => $currency,
                        'stock'        => $available,
                        'is_default'   => true,
                        'tax_rate'     => round($taxRateDecimal * 100, 4),
                        'tax_name'     => TaxCalculationService::rateLabelForProduct($product->id) ?: null,
                    ]]);
                })()
                : $product->variants->map(function (ProductVariant $v) use ($outletId, $product, $currency, $rateMap) {
                // Collect all candidate inventory rows and pick the one with the
                // highest available quantity.
                $candidates = collect([
                    // 1. Variant-specific outlet row
                    $v->inventoryItems->first(),
                    // 2. Variant-specific warehouse row (outlet_id IS NULL)
                    $v->warehouseInventoryItems->first(),
                    // 3. Product-level outlet row (no variant)
                    $product->inventoryItems
                        ->where('outlet_id', $outletId)
                        ->whereNull('product_variant_id')
                        ->first(),
                    // 4. Product-level warehouse row
                    $product->warehouseInventoryItems
                        ->whereNull('product_variant_id')
                        ->first(),
                ])->filter();

                $inventory = $candidates->sortByDesc(fn ($i) => $i->quantity_available)->first();
                $available = $inventory ? $inventory->quantity_available : 0;

                // ── Price resolution ─────────────────────────────────────────
                // Step 1: look for a price row that matches the requested currency exactly.
                $priceRow = $v->prices->firstWhere('currency_code', $currency);

                if ($priceRow) {
                    // Direct match — use it as-is.
                    $regularPrice = (float) $priceRow->regular_price;
                    $salePrice    = $priceRow->sale_price ? (float) $priceRow->sale_price : null;
                } else {
                    // Step 2: no exact row — find the best source price to convert from.
                    // Prefer the base currency row; otherwise fall back to the first row.
                    $basePriceRow = $v->prices->first(
                        fn ($p) => isset($rateMap[$p->currency_code]) && $rateMap[$p->currency_code]['is_base']
                    ) ?? $v->prices->first();

                    if ($basePriceRow
                        && isset($rateMap[$basePriceRow->currency_code], $rateMap[$currency])
                        && $rateMap[$basePriceRow->currency_code]['rate'] > 0
                    ) {
                        $fromRate     = $rateMap[$basePriceRow->currency_code]['rate'];
                        $toRate       = $rateMap[$currency]['rate'];
                        $regularPrice = round(((float) $basePriceRow->regular_price / $fromRate) * $toRate, 2);
                        $salePrice    = $basePriceRow->sale_price
                            ? round(((float) $basePriceRow->sale_price / $fromRate) * $toRate, 2)
                            : null;
                    } else {
                        // No usable exchange rate — return the source price raw.
                        $regularPrice = $basePriceRow ? (float) $basePriceRow->regular_price : 0.0;
                        $salePrice    = $basePriceRow?->sale_price ? (float) $basePriceRow->sale_price : null;
                    }
                }

                $taxRateDecimal = TaxCalculationService::rateForProduct($product->id);

                return [
                    'id'           => $v->id,
                    'sku'          => $v->sku,
                    'variant_name' => $v->variant_name,
                    'attributes'   => $v->attributes ?? [],
                    'price'        => $regularPrice,
                    'sale_price'   => $salePrice,
                    'currency'     => $currency,
                    'stock'        => $available,
                    'is_default'   => (bool) $v->is_default,
                    'tax_rate'     => round($taxRateDecimal * 100, 4),
                    'tax_name'     => TaxCalculationService::rateLabelForProduct($product->id) ?: null,
                ];
            })->values(),
        ];
    }

    private function transformRegister(CashRegister $r): array
    {
        $openedByName = $r->openedBy
            ? trim($r->openedBy->first_name . ' ' . $r->openedBy->last_name) : null;
        $closedByName = $r->closedBy
            ? trim($r->closedBy->first_name . ' ' . $r->closedBy->last_name) : null;

        return [
            'id'                => $r->id,
            'outlet_id'         => $r->outlet_id,
            'opened_by'         => $openedByName,
            'closed_by'         => $closedByName,
            'opening_cash'      => (float) ($r->opening_balance ?? 0),
            'closing_cash'      => $r->closing_balance !== null ? (float) $r->closing_balance : null,
            'expected_cash'     => (float) ($r->expected_cash ?? $r->opening_balance ?? 0),
            'transaction_count' => (int) ($r->transaction_count ?? 0),
            'total_sales'       => (float) ($r->total_sales ?? 0),
            'total_cash_sales'  => (float) ($r->total_cash_sales ?? 0),
            'total_card_sales'  => (float) ($r->total_card_sales ?? 0),
            'total_mpesa_sales' => (float) ($r->total_mpesa_sales ?? 0),
            'total_refunds'     => (float) ($r->total_refunds ?? 0),
            'variance'          => $r->variance !== null ? (float) $r->variance : null,
            'status'            => $r->status,
            'notes'             => $r->opening_notes,
            'opened_at'         => $r->opened_at instanceof \Carbon\Carbon
                ? $r->opened_at->toIso8601String()
                : (string) $r->opened_at,
            'closed_at'         => $r->closed_at instanceof \Carbon\Carbon
                ? $r->closed_at->toIso8601String()
                : (string) ($r->closed_at ?? ''),
        ];
    }

    /**
     * GET /admin/pos/pending-order/open?outlet_id=X
     *
     * Returns the most recent unpaid pending POS order for the given outlet
     * that was created by the authenticated user, or null if none exists.
     * Used on POS mount to allow the cashier to resume an interrupted sale
     * without creating a duplicate order.
     */
    public function getOpenPendingOrder(Request $request): JsonResponse
    {
        $outletId = (int) $request->query('outlet_id');
        if (!$outletId) {
            return response()->json(null, 200);
        }

        $this->authoriseOutletAccess($request->user(), $outletId);

        $order = Order::with(['items', 'payments'])  // FIX 6: payments now eager-loaded
            ->where('order_type', 'pos')
            ->where('outlet_id', $outletId)
            ->where('created_by', $request->user()->id)
            ->where('status', 'pending')
            ->where('payment_status', 'pending')
            ->latest()
            ->first();

        if (!$order) {
            return response()->json(null, 200);
        }

        return response()->json([
            'order_id'     => $order->id,
            'order_number' => $order->order_number,
            'total_amount' => (float) $order->total_amount,
            'currency_code'=> $order->currency_code,
            'order'        => $this->transformSaleOrder($order),
        ], 200);
    }

    // ── Two-step POS checkout ─────────────────────────────────────────────────

    /**
     * PATCH /admin/pos/pending-order/{id}
     *
     * Updates an existing pending (unpaid) POS order — items, quantities,
     * discounts, shipping, and customer — without creating a new order.
     * Used when the cashier resumes an order from the sales history drawer
     * and then makes changes (add/remove items, apply discount, attach customer).
     *
     * Stock is reconciled: previously reserved stock is returned, new stock
     * is deducted. Returns the same shape as createPendingOrder.
     */
    public function updatePendingOrder(Request $request, int $id): JsonResponse
    {
        $order = Order::with(['items'])->where('order_type', 'pos')->findOrFail($id);
        $this->authoriseOutletAccess($request->user(), $order->outlet_id);

        if (!in_array($order->status, ['pending']) || $order->payment_status !== 'pending') {
            return response()->json([
                'message' => 'Only unpaid pending POS orders can be updated.',
            ], 422);
        }

        $validated = $request->validate([
            'customer_id'                          => 'nullable|exists:customers,id',
            'customer_first_name'                  => 'nullable|string|max:255',
            'customer_last_name'                   => 'nullable|string|max:255',
            'customer_phone'                       => 'nullable|string|max:30',
            'customer_email'                       => 'nullable|email|max:255',
            'new_customer'                         => 'nullable|array',
            'new_customer.first_name'              => 'required_with:new_customer|string|max:100',
            'new_customer.last_name'               => 'nullable|string|max:100',
            'new_customer.phone'                   => 'required_with:new_customer|string|max:30',
            'new_customer.email'                   => 'nullable|email|max:255',
            // Country drives currency for international POS orders
            'customer_country_code'                => 'nullable|string|size:2',
            'items'                                => 'nullable|array',
            'items.*.variant_id'                   => 'nullable|exists:product_variants,id',
            'items.*.product_id'                   => 'nullable|exists:products,id',
            'items.*.quantity'                     => 'required|integer|min:1',
            'items.*.unit_price'                   => 'required|numeric|min:0',
            'items.*.discount_type'                => 'nullable|in:none,flat,percent',
            'items.*.discount_value'               => 'nullable|numeric|min:0',
            'cart_discount_type'                   => 'nullable|in:none,flat,percent',
            'cart_discount_value'                  => 'nullable|numeric|min:0',
            'shipping_amount'                      => 'nullable|numeric|min:0',
            'shipping_method_id'                   => 'nullable|exists:shipping_methods,id',
            'notes'                                => 'nullable|string|max:1000',
            'production_items'                     => 'nullable|array',
            'production_items.*.variant_id'        => 'nullable|exists:product_variants,id',
            'production_items.*.product_id'        => 'required_with:production_items|exists:products,id',
            'production_items.*.quantity'          => 'required_with:production_items|integer|min:1',
            'production_items.*.unit_price'        => 'nullable|numeric|min:0',
            'production_items.*.production_notes'  => 'nullable|string|max:1000',
            // FIX 4: accept structured measurement values per MTO item
            'production_items.*.measurement_values' => 'nullable|array',
        ]);

        $outletId     = $order->outlet_id;
        $taxInclusive = TaxCalculationService::isTaxInclusive();

        // ── Currency / international resolution ────────────────────────────────
        $homeCountry         = DB::table('settings')->where('key', 'app_country')->value('value') ?? 'KE';
        $customerCountryCode = strtoupper($validated['customer_country_code'] ?? '');
        $isInternational     = $customerCountryCode !== '' && $customerCountryCode !== strtoupper($homeCountry);

        if ($customerCountryCode !== '') {
            $countryCurrency  = DB::table('countries')
                ->where('code', $customerCountryCode)
                ->value('default_currency_code');
            $activeCurrencies = DB::table('currencies')->where('is_active', true)->pluck('code')->toArray();
            if ($countryCurrency && (empty($activeCurrencies) || in_array($countryCurrency, $activeCurrencies))) {
                $resolvedCurrency = $countryCurrency;
            } else {
                $outlet = Outlet::find($outletId);
                $resolvedCurrency = $this->resolveCurrency($outlet);
            }
        } else {
            $resolvedCurrency = $order->currency_code; // keep existing
        }

        DB::beginTransaction();
        try {
            // ── 1. Return stock for old items ─────────────────────────────────
            // MTO items had no inventory deducted, so skip them gracefully.
            foreach ($order->items as $oldItem) {
                if (!$oldItem->product_variant_id) continue;

                $inv = InventoryItem::where('outlet_id', $outletId)
                    ->where('product_variant_id', $oldItem->product_variant_id)
                    ->lockForUpdate()->first();
                if (!$inv) {
                    $inv = InventoryItem::whereNull('outlet_id')
                        ->where('product_variant_id', $oldItem->product_variant_id)
                        ->lockForUpdate()->first();
                }
                if ($inv) {
                    $inv->quantity_on_hand += $oldItem->quantity;
                    $inv->save();
                }
                // If $inv is still null the line was MTO — nothing to return.
            }

            // ── 2. Delete old order items ─────────────────────────────────────
            OrderItem::where('order_id', $order->id)->delete();

            // ── 3. Validate stock + build new items ───────────────────────────
            $itemsData    = [];
            $itemSubtotal = 0;

            foreach ($validated['items'] ?? [] as $item) {
                $variantId    = $item['variant_id'] ?? null;
                $variantModel = $variantId ? ProductVariant::find($variantId) : null;
                $productId    = $variantModel?->product_id ?? (int)($item['product_id'] ?? 0);

                $inv = null;
                if ($variantId) {
                    $inv = InventoryItem::where('outlet_id', $outletId)
                        ->where('product_variant_id', $variantId)
                        ->lockForUpdate()->first();
                }
                if (!$inv && $productId) {
                    $inv = InventoryItem::where('outlet_id', $outletId)
                        ->where('product_id', $productId)
                        ->whereNull('product_variant_id')->lockForUpdate()->first();
                }
                if (!$inv && $variantId) {
                    $inv = InventoryItem::whereNull('outlet_id')
                        ->where('product_variant_id', $variantId)
                        ->lockForUpdate()->first();
                }
                if (!$inv && $productId) {
                    $inv = InventoryItem::whereNull('outlet_id')
                        ->where('product_id', $productId)
                        ->whereNull('product_variant_id')->lockForUpdate()->first();
                }

                $available = $inv ? $inv->quantity_available : 0;
                if (!$inv || $available < $item['quantity']) {
                    DB::rollBack();
                    $name = $variantModel?->product?->translations->first()?->name
                         ?? ($productId ? Product::with('translations')->find($productId)?->translations->first()?->name : null)
                         ?? "Product #{$productId}";
                    return response()->json([
                        'message' => "Insufficient stock for \"{$name}\". Available: {$available}.",
                    ], 422);
                }

                $lineBase     = $item['unit_price'] * $item['quantity'];
                $discType     = $item['discount_type'] ?? 'none';
                $discVal      = (float)($item['discount_value'] ?? 0);
                $lineDiscount = match($discType) {
                    'flat'    => min($discVal, $lineBase),
                    'percent' => ($lineBase * $discVal) / 100,
                    default   => 0.0,
                };
                $lineSubtotal  = $lineBase - $lineDiscount;
                $itemSubtotal += $lineSubtotal;

                $taxCalcLine = TaxCalculationService::calculateLine(
                    $item['unit_price'], $item['quantity'], $productId, $taxInclusive
                );
                $lineTotal = $taxInclusive
                    ? $lineSubtotal
                    : $lineSubtotal + round($taxCalcLine['tax_amount'], 2);

                $variant     = $variantModel ? $variantModel->load('product.translations') : null;
                $productName = $variant?->product?->translations->firstWhere('language_code', 'en')?->name
                    ?? $variant?->product?->translations->first()?->name
                    ?? ($productId ? Product::with('translations')->find($productId)?->translations->firstWhere('language_code', 'en')?->name : null)
                    ?? 'Unknown';

                $itemsData[] = [
                    'variant'         => $variant,
                    'product_name'    => $productName,
                    'variant_id'      => $variantId,
                    'product_id'      => $productId,
                    'quantity'        => $item['quantity'],
                    'unit_price'      => $item['unit_price'],
                    // FIX 1: persist raw discount type + value
                    'discount_type'   => $discType,
                    'discount_value'  => $discVal,
                    'discount_amount' => round($lineDiscount, 2),
                    'tax_amount'      => round($taxCalcLine['tax_amount'], 2),
                    'total_price'     => round($lineTotal, 2),
                    'inventory'       => $inv,
                    'is_mto'          => false,
                ];
            }

            // ── Build order lines for MTO items (no stock deduction) ──────────
            foreach ($validated['production_items'] ?? [] as $pi) {
                $mtoVariantId = $pi['variant_id'] ?? null;
                $mtoVariant   = $mtoVariantId ? ProductVariant::with('product.translations')->find($mtoVariantId) : null;
                $mtoProductId = $mtoVariant?->product_id ?? (int)($pi['product_id'] ?? 0);
                $mtoProduct   = (!$mtoVariant && $mtoProductId) ? Product::with('translations')->find($mtoProductId) : null;
                $mtoName      = $mtoVariant?->product?->translations->firstWhere('language_code', 'en')?->name
                    ?? $mtoVariant?->product?->translations->first()?->name
                    ?? $mtoProduct?->translations->firstWhere('language_code', 'en')?->name
                    ?? $mtoProduct?->translations->first()?->name
                    ?? 'Unknown';

                $mtoUnitPrice = (float)($pi['unit_price'] ?? 0);
                if ($mtoUnitPrice === 0.0 && $mtoVariant) {
                    // Variable product — look up variant prices
                    $priceRow     = $mtoVariant->prices->firstWhere('currency_code', $resolvedCurrency)
                        ?? $mtoVariant->prices->first();
                    $mtoUnitPrice = $priceRow ? (float)$priceRow->regular_price : 0.0;
                }
                if ($mtoUnitPrice === 0.0 && !$mtoVariant && $mtoProductId) {
                    // Simple product — look up product-level prices (product_variant_id IS NULL)
                    $productPriceRow = \App\Models\ProductPrice::where('product_id', $mtoProductId)
                        ->whereNull('product_variant_id')
                        ->where('currency_code', $resolvedCurrency)
                        ->first()
                        ?? \App\Models\ProductPrice::where('product_id', $mtoProductId)
                            ->whereNull('product_variant_id')
                            ->first();
                    $mtoUnitPrice = $productPriceRow ? (float)$productPriceRow->regular_price : 0.0;
                }

                $mtoBase      = $mtoUnitPrice * (int)$pi['quantity'];
                $mtoTaxCalc   = TaxCalculationService::calculateLine($mtoUnitPrice, (int)$pi['quantity'], $mtoProductId, $taxInclusive);
                $mtoLineTotal = $taxInclusive ? $mtoBase : $mtoBase + round($mtoTaxCalc['tax_amount'], 2);
                $itemSubtotal += $mtoBase;

                $itemsData[] = [
                    'variant'            => $mtoVariant,
                    'product_name'       => $mtoName,
                    'variant_id'         => $mtoVariantId,
                    'product_id'         => $mtoProductId,
                    'quantity'           => (int)$pi['quantity'],
                    'unit_price'         => $mtoUnitPrice,
                    'discount_type'      => 'none',
                    'discount_value'     => 0.0,
                    'discount_amount'    => 0.0,
                    'tax_amount'         => round($mtoTaxCalc['tax_amount'], 2),
                    'total_price'        => round($mtoLineTotal, 2),
                    'inventory'          => null,
                    'is_mto'             => true,
                    'production_notes'   => $pi['production_notes'] ?? null,
                    // FIX 4: structured measurement values for restore
                    'measurement_values' => $pi['measurement_values'] ?? null,
                ];
            }

            // ── 4. Recalculate totals ─────────────────────────────────────────
            $cartDiscType = $validated['cart_discount_type'] ?? 'none';
            $cartDiscVal  = (float)($validated['cart_discount_value'] ?? 0);
            $cartDiscount = match($cartDiscType) {
                'flat'    => min($cartDiscVal, $itemSubtotal),
                'percent' => ($itemSubtotal * $cartDiscVal) / 100,
                default   => 0.0,
            };
            $afterDiscount = $itemSubtotal - $cartDiscount;
            $taxAmount     = round(collect($itemsData)->sum('tax_amount'), 2);
            $shippingAmt   = round((float)($validated['shipping_amount'] ?? 0), 2);
            $totalAmount   = round($afterDiscount + ($taxInclusive ? 0 : $taxAmount) + $shippingAmt, 2);

            // ── 5. Customer resolution ────────────────────────────────────────
            $customerId   = $validated['customer_id'] ?? null;
            $linkedUserId = null;
            if ($customerId) {
                $cr = \App\Models\Customer::find($customerId);
                $linkedUserId = $cr?->user_id ?? null;
            }
            if (!$customerId && !empty($validated['new_customer'])) {
                $nc = $validated['new_customer'];
                $newCustomer = \App\Models\Customer::create([
                    'first_name' => $nc['first_name'],
                    'last_name'  => $nc['last_name'] ?? '',   // Customer model's creating() guard also defends against null here - belt-and-suspenders
                    'phone'      => $nc['phone'],
                    'email'      => $nc['email'] ?? null,
                    'created_by' => $request->user()->id,
                ]);
                $customerId = $newCustomer->id;
                $linkedUserId = null;
            }

            // ── 6. Update the order record ────────────────────────────────────
            $order->update([
                'currency_code'       => $resolvedCurrency,
                'customer_country_code' => $customerCountryCode ?: $order->customer_country_code,
                'is_international'    => $customerCountryCode !== '' ? $isInternational : $order->is_international,
                'subtotal'            => round($itemSubtotal, 2),
                'discount_amount'     => round($cartDiscount, 2),
                // FIX 2: persist raw cart discount type + value for lossless restore
                'cart_discount_type'  => $cartDiscType,
                'cart_discount_value' => $cartDiscVal,
                'tax_amount'          => $taxAmount,
                'prices_include_tax'  => $taxInclusive,
                'shipping_amount'     => $shippingAmt,
                'total_amount'        => $totalAmount,
                'user_id'             => $linkedUserId ?? $order->user_id,
                // FIX 3: persist customer FK so restore can re-link the record
                'customer_id'         => $customerId ?? $order->customer_id,
                'customer_first_name' => $validated['customer_first_name'] ?? $order->customer_first_name,
                'customer_last_name'  => $validated['customer_last_name']  ?? $order->customer_last_name,
                'customer_phone'      => $validated['customer_phone']       ?? $order->customer_phone,
                'customer_email'      => $validated['customer_email']       ?? $order->customer_email,
                'notes'               => $validated['notes']                ?? $order->notes,
            ]);

            // ── 7. Create new order items + deduct updated stock ──────────────
            foreach ($itemsData as $item) {
                OrderItem::create([
                    'order_id'           => $order->id,
                    'product_id'         => $item['product_id'] ?: ($item['variant']?->product_id),
                    'product_variant_id' => $item['variant_id'],
                    'product_name'       => $item['product_name'],
                    'variant_name'       => $item['variant']?->variant_name,
                    'sku'                => $item['variant']?->sku ?? ($item['product_id'] ? Product::find($item['product_id'])?->sku : null),
                    'quantity'           => $item['quantity'],
                    'unit_price'         => $item['unit_price'],
                    // FIX 1: persist raw discount type + value for lossless restore
                    'discount_type'      => $item['discount_type'],
                    'discount_value'     => $item['discount_value'],
                    'discount_amount'    => $item['discount_amount'],
                    'tax_amount'         => $item['tax_amount'],
                    'total_price'        => $item['total_price'],
                    'notes'              => ($item['is_mto'] ?? false)
                        ? '__MTO__' . (!empty($item['production_notes']) ? '|' . $item['production_notes'] : '')
                        : null,
                    // FIX 4: persist structured measurement values as JSON
                    'measurement_values' => !empty($item['measurement_values'])
                        ? json_encode($item['measurement_values'])
                        : null,
                ]);
                // MTO lines have no inventory row to deduct from
                if (!($item['is_mto'] ?? false) && $item['inventory']) {
                    $item['inventory']->adjustQuantity(
                        -(int) round($item['quantity']),
                        'sale',
                        Order::class,
                        $order->id,
                        $request->user()->id
                    );
                }
            }

            DB::commit();

            return response()->json([
                'message'       => 'Order updated.',
                'order_id'      => $order->id,
                'order_number'  => $order->order_number,
                'total_amount'  => $totalAmount,
                'currency_code' => $resolvedCurrency,
                'is_international' => $isInternational,
                'order'         => $this->transformSaleOrder($order->fresh(['items', 'payments'])),  // FIX 6
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('POS updatePendingOrder failed', ['order_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to update order. Please try again.'], 500);
        }
    }


    public function createPendingOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outlet_id'                            => 'required|exists:outlets,id',
            'customer_id'                          => 'nullable|exists:customers,id',
            'customer_first_name'                  => 'nullable|string|max:255',
            'customer_last_name'                   => 'nullable|string|max:255',
            'customer_phone'                       => 'nullable|string|max:30',
            'customer_email'                       => 'nullable|email|max:255',
            'new_customer'                         => 'nullable|array',
            'new_customer.first_name'              => 'required_with:new_customer|string|max:100',
            'new_customer.last_name'               => 'nullable|string|max:100',
            'new_customer.phone'                   => 'required_with:new_customer|string|max:30',
            'new_customer.email'                   => 'nullable|email|max:255',
            // Country drives currency for international POS orders
            'customer_country_code'                => 'nullable|string|size:2',
            // items[] contains regular (in-stock) lines only.
            // MTO lines travel exclusively via production_items[] and are
            // excluded from items[] by the frontend to avoid the stock check.
            // A cart must have at least one entry across items or production_items.
            'items'                                => 'nullable|array',
            'items.*.variant_id'                   => 'nullable|exists:product_variants,id',
            'items.*.product_id'                   => 'nullable|exists:products,id',
            'items.*.quantity'                     => 'required|integer|min:1',
            'items.*.unit_price'                   => 'required|numeric|min:0',
            'items.*.discount_type'                => 'nullable|in:none,flat,percent',
            'items.*.discount_value'               => 'nullable|numeric|min:0',
            'cart_discount_type'                   => 'nullable|in:none,flat,percent',
            'cart_discount_value'                  => 'nullable|numeric|min:0',
            'shipping_amount'                      => 'nullable|numeric|min:0',
            'shipping_method_id'                   => 'nullable|exists:shipping_methods,id',
            'notes'                                => 'nullable|string|max:1000',
            'is_deposit'                           => 'nullable|boolean',
            'deposit_amount'                       => 'nullable|numeric|min:0.01',
            'production_items'                     => 'nullable|array',
            'production_items.*.variant_id'        => 'nullable|exists:product_variants,id',
            'production_items.*.product_id'        => 'required_with:production_items|exists:products,id',
            'production_items.*.quantity'          => 'required_with:production_items|integer|min:1',
            'production_items.*.unit_price'        => 'nullable|numeric|min:0',
            'production_items.*.production_notes'  => 'nullable|string|max:1000',
            // FIX 4: accept structured measurement values per MTO item
            'production_items.*.measurement_values' => 'nullable|array',
        ]);

        // Guard: at least one regular item or one production item must be present
        if (empty($validated['items']) && empty($validated['production_items'])) {
            return response()->json(['message' => 'The cart must contain at least one item.'], 422);
        }

        $user     = $request->user();
        $outletId = (int) $validated['outlet_id'];
        $this->authoriseOutletAccess($user, $outletId);

        $outlet       = Outlet::findOrFail($outletId);
        $taxInclusive = TaxCalculationService::isTaxInclusive();

        // ── Currency / international resolution ────────────────────────────────
        // If the cashier specified a customer country, derive the currency from
        // that country's default (validated against active currencies).  Otherwise
        // fall back to the outlet-level currency.
        $homeCountry         = DB::table('settings')->where('key', 'app_country')->value('value') ?? 'KE';
        $customerCountryCode = strtoupper($validated['customer_country_code'] ?? '');
        $isInternational     = $customerCountryCode !== '' && $customerCountryCode !== strtoupper($homeCountry);

        if ($customerCountryCode !== '') {
            // Look up the country's default currency
            $countryCurrency = DB::table('countries')
                ->where('code', $customerCountryCode)
                ->value('default_currency_code');

            // Validate against active currencies; fall back to outlet currency on mismatch
            $activeCurrencies = DB::table('currencies')->where('is_active', true)->pluck('code')->toArray();
            if ($countryCurrency && (empty($activeCurrencies) || in_array($countryCurrency, $activeCurrencies))) {
                $currencyCode = $countryCurrency;
            } else {
                $currencyCode = $this->resolveCurrency($outlet);
            }
        } else {
            $currencyCode = $this->resolveCurrency($outlet);
        }

        DB::beginTransaction();
        try {
            $itemsData    = [];
            $itemSubtotal = 0;

            foreach ($validated['items'] ?? [] as $item) {
                $variantId    = $item['variant_id'] ?? null;
                $variantModel = $variantId ? ProductVariant::find($variantId) : null;
                $productId    = $variantModel?->product_id ?? (int)($item['product_id'] ?? 0);

                // Collect all candidate rows and pick the one with the most
                // available stock. This handles the case where a variant-specific
                // row exists with 0 stock while a product-level row has the stock.
                $candidateQueries = [];

                if ($variantId) {
                    // 1. Outlet-specific variant row
                    $row = InventoryItem::where('outlet_id', $outletId)
                        ->where('product_variant_id', $variantId)
                        ->lockForUpdate()->first();
                    if ($row) $candidateQueries[] = $row;
                }

                if ($productId) {
                    // 2. Outlet-specific product-level row
                    $row = InventoryItem::where('outlet_id', $outletId)
                        ->where('product_id', $productId)
                        ->whereNull('product_variant_id')
                        ->lockForUpdate()->first();
                    if ($row) $candidateQueries[] = $row;

                    if ($variantId) {
                        // 3. Warehouse variant row (outlet_id IS NULL)
                        $row = InventoryItem::whereNull('outlet_id')
                            ->where('product_variant_id', $variantId)
                            ->lockForUpdate()->first();
                        if ($row) $candidateQueries[] = $row;
                    }

                    // 4. Warehouse product-level row
                    $row = InventoryItem::whereNull('outlet_id')
                        ->where('product_id', $productId)
                        ->whereNull('product_variant_id')
                        ->lockForUpdate()->first();
                    if ($row) $candidateQueries[] = $row;
                }

                // Pick the row with the most available stock
                $inventory = collect($candidateQueries)
                    ->sortByDesc(fn ($i) => $i->quantity_available)
                    ->first();

                $available = $inventory ? $inventory->quantity_available : 0;
                if (!$inventory || $available < $item['quantity']) {
                    DB::rollBack();
                    $name = $variantModel?->product?->translations->first()?->name
                         ?? ($productId ? Product::with('translations')->find($productId)?->translations->first()?->name : null)
                         ?? "Product #{$productId}";
                    return response()->json(['message' => "Insufficient stock for \"{$name}\". Available: {$available}."], 422);
                }

                $lineBase     = $item['unit_price'] * $item['quantity'];
                $discType     = $item['discount_type'] ?? 'none';
                $discVal      = (float)($item['discount_value'] ?? 0);
                $lineDiscount = match ($discType) {
                    'flat'    => min($discVal, $lineBase),
                    'percent' => ($lineBase * $discVal) / 100,
                    default   => 0.0,
                };
                $lineSubtotal  = $lineBase - $lineDiscount;
                $itemSubtotal += $lineSubtotal;

                $taxCalcLine = TaxCalculationService::calculateLine($item['unit_price'], $item['quantity'], $productId, $taxInclusive);
                $lineSubtotalForOrder = $taxInclusive ? $lineSubtotal : $lineSubtotal + round($taxCalcLine['tax_amount'], 2);

                $variant     = $variantModel ? $variantModel->load('product.translations') : null;
                $productName = $variant?->product?->translations->firstWhere('language_code', 'en')?->name
                    ?? $variant?->product?->translations->first()?->name
                    ?? ($productId ? Product::with('translations')->find($productId)?->translations->firstWhere('language_code', 'en')?->name : null)
                    ?? 'Unknown';

                $itemsData[] = [
                    'variant'         => $variant,
                    'product_name'    => $productName,
                    'variant_id'      => $variantId,
                    'product_id'      => $productId,
                    'quantity'        => $item['quantity'],
                    'unit_price'      => $item['unit_price'],
                    // FIX 1: persist raw discount type + value for lossless restore
                    'discount_type'   => $discType,
                    'discount_value'  => $discVal,
                    'discount_amount' => round($lineDiscount, 2),
                    'tax_amount'      => round($taxCalcLine['tax_amount'], 2),
                    'total_price'     => round($lineSubtotalForOrder, 2),
                    'inventory'       => $inventory,
                    'is_mto'          => false,
                ];
            }

            // ── Build order lines for MTO (production) items ──────────────────
            // These travel via production_items[] only. No stock deduction;
            // the item is made to order and inventory will be added on completion.
            foreach ($validated['production_items'] ?? [] as $pi) {
                $mtoVariantId = $pi['variant_id'] ?? null;
                $mtoVariant  = $mtoVariantId ? ProductVariant::with('product.translations')->find($mtoVariantId) : null;
                $mtoProductId = $mtoVariant?->product_id ?? (int)($pi['product_id'] ?? 0);
                $mtoProduct   = (!$mtoVariant && $mtoProductId) ? Product::with('translations')->find($mtoProductId) : null;
                $mtoName     = $mtoVariant?->product?->translations->firstWhere('language_code', 'en')?->name
                    ?? $mtoVariant?->product?->translations->first()?->name
                    ?? $mtoProduct?->translations->firstWhere('language_code', 'en')?->name
                    ?? $mtoProduct?->translations->first()?->name
                    ?? 'Unknown';

                // Use the unit_price from the production_items entry if provided,
                // otherwise look it up from the variant's (or product-level) prices table.
                $mtoUnitPrice = (float)($pi['unit_price'] ?? 0);
                if ($mtoUnitPrice === 0.0 && $mtoVariant) {
                    // Variable product — look up variant prices
                    $priceRow     = $mtoVariant->prices->firstWhere('currency_code', $currencyCode)
                        ?? $mtoVariant->prices->first();
                    $mtoUnitPrice = $priceRow ? (float)$priceRow->regular_price : 0.0;
                }
                if ($mtoUnitPrice === 0.0 && !$mtoVariant && $mtoProductId) {
                    // Simple product — look up product-level prices (product_variant_id IS NULL)
                    $productPriceRow = \App\Models\ProductPrice::where('product_id', $mtoProductId)
                        ->whereNull('product_variant_id')
                        ->where('currency_code', $currencyCode)
                        ->first()
                        ?? \App\Models\ProductPrice::where('product_id', $mtoProductId)
                            ->whereNull('product_variant_id')
                            ->first();
                    $mtoUnitPrice = $productPriceRow ? (float)$productPriceRow->regular_price : 0.0;
                }

                $mtoBase         = $mtoUnitPrice * (int)$pi['quantity'];
                $mtoTaxCalc      = TaxCalculationService::calculateLine($mtoUnitPrice, (int)$pi['quantity'], $mtoProductId, $taxInclusive);
                $mtoLineTotal    = $taxInclusive ? $mtoBase : $mtoBase + round($mtoTaxCalc['tax_amount'], 2);
                $itemSubtotal   += $mtoBase;

                $itemsData[] = [
                    'variant'            => $mtoVariant,
                    'product_name'       => $mtoName,
                    'variant_id'         => $mtoVariantId,
                    'product_id'         => $mtoProductId,
                    'quantity'           => (int)$pi['quantity'],
                    'unit_price'         => $mtoUnitPrice,
                    'discount_type'      => 'none',
                    'discount_value'     => 0.0,
                    'discount_amount'    => 0.0,
                    'tax_amount'         => round($mtoTaxCalc['tax_amount'], 2),
                    'total_price'        => round($mtoLineTotal, 2),
                    'inventory'          => null,   // no inventory deduction for MTO
                    'is_mto'             => true,
                    'production_notes'   => $pi['production_notes'] ?? null,
                    // FIX 4: structured measurement values for restore
                    'measurement_values' => $pi['measurement_values'] ?? null,
                ];
            }

            $cartDiscType = $validated['cart_discount_type'] ?? 'none';
            $cartDiscVal  = (float)($validated['cart_discount_value'] ?? 0);
            $cartDiscount = match ($cartDiscType) {
                'flat'    => min($cartDiscVal, $itemSubtotal),
                'percent' => ($itemSubtotal * $cartDiscVal) / 100,
                default   => 0.0,
            };
            $afterDiscount = $itemSubtotal - $cartDiscount;
            $taxAmount     = round(collect($itemsData)->sum('tax_amount'), 2);
            $shippingAmt   = round((float)($validated['shipping_amount'] ?? 0), 2);
            $totalAmount   = round($afterDiscount + ($taxInclusive ? 0 : $taxAmount) + $shippingAmt, 2);
            $isDeposit     = !empty($validated['is_deposit']);
            $depositAmt    = $isDeposit ? round((float)($validated['deposit_amount'] ?? 0), 2) : null;

            $customerId   = $validated['customer_id'] ?? null;
            $linkedUserId = null;
            if ($customerId) {
                $cr = \App\Models\Customer::find($customerId);
                $linkedUserId = $cr?->user_id ?? null;
            }
            if (!$customerId && !empty($validated['new_customer'])) {
                $nc = $validated['new_customer'];
                $newCustomer = \App\Models\Customer::create([
                    'first_name' => $nc['first_name'],
                    'last_name'  => $nc['last_name'] ?? '',   // Customer model's creating() guard also defends against null here - belt-and-suspenders
                    'phone'      => $nc['phone'],
                    'email'      => $nc['email'] ?? null,
                    'created_by' => $user->id,
                ]);
                $customerId = $newCustomer->id;
                $validated['customer_first_name'] = $validated['customer_first_name'] ?? $nc['first_name'];
                $validated['customer_last_name']  = $validated['customer_last_name']  ?? ($nc['last_name'] ?? null);
                $validated['customer_phone']      = $validated['customer_phone']      ?? $nc['phone'];
                $validated['customer_email']      = $validated['customer_email']      ?? ($nc['email'] ?? null);
            }

            $prefix      = 'POS-' . date('ymd') . '-';
            $orderNumber = $this->generateUniqueOrderNumber($prefix);

            $order = Order::create([
                'order_number'        => $orderNumber,
                'outlet_id'           => $outletId,
                'user_id'             => $linkedUserId,
                'order_type'          => 'pos',
                'status'              => 'pending',
                'payment_status'      => 'pending',
                'currency_code'       => $currencyCode,
                'customer_country_code' => $customerCountryCode ?: null,
                'is_international'    => $isInternational,
                'subtotal'            => round($itemSubtotal, 2),
                'discount_amount'     => round($cartDiscount, 2),
                // FIX 2: persist raw cart discount type + value for lossless restore
                'cart_discount_type'  => $cartDiscType,
                'cart_discount_value' => $cartDiscVal,
                'tax_amount'          => $taxAmount,
                'prices_include_tax'  => $taxInclusive,
                'shipping_amount'     => $shippingAmt,
                'total_amount'        => $totalAmount,
                // FIX 3: persist customer FK so restore can re-link the record
                'customer_id'         => $customerId,
                'customer_first_name' => $validated['customer_first_name'] ?? null,
                'customer_last_name'  => $validated['customer_last_name']  ?? null,
                'customer_phone'      => $validated['customer_phone']       ?? null,
                'customer_email'      => $validated['customer_email']       ?? null,
                'deposit_amount'      => $depositAmt,
                'notes'               => $validated['notes'] ?? null,
                'created_by'          => $user->id,
            ]);

            foreach ($itemsData as $item) {
                OrderItem::create([
                    'order_id'           => $order->id,
                    'product_id'         => $item['product_id'] ?: ($item['variant']?->product_id),
                    'product_variant_id' => $item['variant_id'],
                    'product_name'       => $item['product_name'],
                    'variant_name'       => $item['variant']?->variant_name,
                    'sku'                => $item['variant']?->sku ?? ($item['product_id'] ? Product::find($item['product_id'])?->sku : null),
                    'quantity'           => $item['quantity'],
                    'unit_price'         => $item['unit_price'],
                    // FIX 1: persist raw discount type + value for lossless restore
                    'discount_type'      => $item['discount_type'],
                    'discount_value'     => $item['discount_value'],
                    'discount_amount'    => $item['discount_amount'],
                    'tax_amount'         => $item['tax_amount'],
                    'total_price'        => $item['total_price'],
                    'notes'              => ($item['is_mto'] ?? false)
                        ? '__MTO__' . (!empty($item['production_notes']) ? '|' . $item['production_notes'] : '')
                        : null,
                    // FIX 4: persist structured measurement values as JSON
                    'measurement_values' => !empty($item['measurement_values'])
                        ? json_encode($item['measurement_values'])
                        : null,
                ]);
                // MTO lines have no inventory to deduct — the item is made to order.
                if (!($item['is_mto'] ?? false) && $item['inventory']) {
                    $item['inventory']->adjustQuantity(-$item['quantity'], 'sale', Order::class, $order->id, $user->id);
                }
            }
            $raisedProductionOrders = [];
            foreach ($validated['production_items'] ?? [] as $pi) {
                $prodNum = 'PO-' . date('ymd') . '-' . strtoupper(Str::random(5));
                while (DB::table('production_orders')->where('order_number', $prodNum)->exists()) {
                    $prodNum = 'PO-' . date('ymd') . '-' . strtoupper(Str::random(5));
                }
                DB::table('production_orders')->insert([
                    'order_number'       => $prodNum,
                    'product_id'         => $pi['product_id'],
                    'product_variant_id' => $pi['variant_id'] ?? null,
                    'quantity'           => $pi['quantity'],
                    'priority'           => 'normal',
                    'status'             => 'draft',
                    'outlet_id'          => $outletId,
                    'customer_order_id'  => $order->id,
                    'is_customer_order'  => true,
                    'due_date'           => now()->addDays(14)->toDateString(),
                    'notes'              => $pi['production_notes'] ?? null,
                    'created_by'         => $user->id,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);
                $raisedProductionOrders[] = $prodNum;
            }

            DB::commit();

            return response()->json([
                'message'           => 'Order created. Awaiting payment.',
                'order_id'          => $order->id,
                'order_number'      => $order->order_number,
                'total_amount'      => $totalAmount,
                'currency_code'     => $currencyCode,
                'is_international'  => $isInternational,
                'is_deposit'        => $isDeposit,
                'deposit_amount'    => $depositAmt,
                'order'             => $this->transformSaleOrder($order->load(['items', 'payments'])),  // FIX 6
                'production_orders' => $raisedProductionOrders,
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('POS pending order failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to create order. Please try again.'], 500);
        }
    }

    /**
     * POST /admin/pos/pending-order/{id}/pay
     *
     * Phase 2 — records payments against a pending POS order.
     * Handles proof-of-payment file upload and advances order status.
     */
    public function recordPosPay(Request $request, int $id): JsonResponse
    {
        $order = Order::with('items')->where('order_type', 'pos')->findOrFail($id);
        $this->authoriseOutletAccess($request->user(), $order->outlet_id);

        if (!in_array($order->payment_status, ['pending', 'partial', 'deposit'])) {
            return response()->json(['message' => 'Order is already paid or cannot accept further payments.'], 422);
        }

        $validated = $request->validate([
            'payments'                 => 'nullable|array|min:1',
            'payments.*.method'        => 'required_with:payments|string|max:50',
            'payments.*.amount'        => 'required_with:payments|numeric|min:0.01',
            'payments.*.reference'     => 'nullable|string|max:255',
            'payments.*.cash_received' => 'nullable|numeric|min:0',
            'method'                   => 'nullable|string|max:50',
            'amount'                   => 'nullable|numeric|min:0.01',
            'reference'                => 'nullable|string|max:255',
            'cash_received'            => 'nullable|numeric|min:0',
            'proof_of_payment'         => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
            'is_deposit'               => 'nullable|boolean',
            'deposit_amount'           => 'nullable|numeric|min:0.01',
        ]);

        $paymentsInput = $validated['payments'] ?? [[
            'method'        => $validated['method'] ?? 'cash',
            'amount'        => $validated['amount'] ?? $order->total_amount,
            'reference'     => $validated['reference'] ?? null,
            'cash_received' => $validated['cash_received'] ?? null,
        ]];

        $isDeposit   = !empty($validated['is_deposit']);
        $depositAmt  = $isDeposit ? (float)($validated['deposit_amount'] ?? 0) : null;
        $totalPaying = collect($paymentsInput)->sum('amount');
        $required    = $isDeposit ? $depositAmt : $order->total_amount;

        if ($totalPaying < $required - 0.01) {
            return response()->json([
                'message' => "Payment ({$totalPaying}) does not cover " . ($isDeposit ? "deposit ({$required})" : "order total ({$required})") . ".",
            ], 422);
        }

        // Load payment method types once so any method configured with type='cash'
        // is treated identically to the built-in 'cash' code: no approval needed,
        // but admin is notified immediately via paymentReceived.
        $inputCodes    = collect($paymentsInput)->pluck('method')->filter()->unique()->toArray();
        $pmMethodTypes = DB::table('payment_methods')
            ->whereIn('code', $inputCodes)
            ->pluck('type', 'code');

        $isCashType = fn (string $code): bool =>
            $code === 'cash' || ($pmMethodTypes->get($code) === 'cash');

        DB::beginTransaction();
        try {
            $primaryChange    = 0;
            $totalCash        = 0;
            $totalCard        = 0;
            $totalMpesa       = 0;
            $totalSales       = 0;
            $proofPaymentId   = null;

            foreach ($paymentsInput as $pmt) {
                $pmtAmount     = (float)$pmt['amount'];
                $pmtMethod     = $pmt['method'] ?? 'other';
                // Cash (by code or DB type) is an immediate verified transaction — no
                // approval needed.  M-Pesa and card are gateway-verified.  Everything
                // else (bank_transfer, other, cheque…) requires admin review.
                $pmtIsCash     = $isCashType($pmtMethod);
                $isAutomated   = $pmtIsCash || in_array($pmtMethod, ['mpesa', 'card', 'card_paystack']);
                $needsApproval = !$isAutomated;

                // Validate cash tendered (applies to all cash-type methods)
                $pmtCashRec = null;
                $pmtChange  = null;
                if ($pmtIsCash) {
                    $cashRec = (float)($pmt['cash_received'] ?? 0);
                    if ($cashRec > 0 && $cashRec < $pmtAmount) {
                        DB::rollBack();
                        return response()->json(['message' => 'Cash received is less than payment amount.'], 422);
                    }
                    $register = CashRegister::where('outlet_id', $order->outlet_id)
                        ->where('opened_by', $request->user()->id)
                        ->where('status', 'open')
                        ->latest('opened_at')
                        ->first();
                    if (!$register) {
                        DB::rollBack();
                        return response()->json(['message' => 'Cash register is not open. Please open your register first.'], 422);
                    }
                    $primaryChange = max(0, ($cashRec ?: $pmtAmount) - $pmtAmount);
                    $pmtCashRec    = $cashRec ?: $pmtAmount;
                    $pmtChange     = $primaryChange;
                }

                $payment = Payment::create([
                    'order_id'           => $order->id,
                    'amount'             => $pmtAmount,
                    'currency_code'      => $order->currency_code,
                    'payment_method'     => $pmtMethod,
                    'status'             => $needsApproval ? 'pending' : 'paid',
                    'provider_reference' => $pmt['reference'] ?? null,
                    'phone_number'       => $pmtMethod === 'mpesa' ? ($order->customer_phone ?? null) : null,
                    'cash_received'      => $pmtCashRec,
                    'change_given'       => $pmtChange,
                    'paid_at'            => $needsApproval ? null : now(),
                    'tax_inclusive'      => $order->prices_include_tax ?? true,
                    'requires_approval'  => $needsApproval,
                    'approval_status'    => $needsApproval ? 'pending_review' : null,
                ]);

                if (!$proofPaymentId && $pmtMethod !== 'cash') {
                    $proofPaymentId = $payment->id;
                }

                $totalSales += $pmtAmount;
                if ($pmtIsCash)                                    $totalCash  += $pmtAmount;
                elseif (in_array($pmtMethod, ['card','card_paystack'])) $totalCard  += $pmtAmount;
                elseif ($pmtMethod === 'mpesa')                    $totalMpesa += $pmtAmount;
            }

            // Post this balance payment to THIS user's register — recorded whether
            // or not it included cash, so a non-cash balance payment isn't dropped.
            if ($totalSales > 0) {
                $register = CashRegister::where('outlet_id', $order->outlet_id)
                    ->where('opened_by', $request->user()->id)
                    ->where('status', 'open')
                    ->latest('opened_at')
                    ->first();
                if ($register) {
                    DB::table('cash_registers')->where('id', $register->id)->update([
                        'total_sales'       => DB::raw("total_sales + {$totalSales}"),
                        'total_cash_sales'  => DB::raw("total_cash_sales + {$totalCash}"),
                        'total_card_sales'  => DB::raw("total_card_sales + {$totalCard}"),
                        'total_mpesa_sales' => DB::raw("total_mpesa_sales + {$totalMpesa}"),
                        'transaction_count' => DB::raw('transaction_count + 1'),
                        'expected_cash'     => DB::raw("expected_cash + {$totalCash}"),
                        'updated_at'        => now(),
                    ]);

                    // MON-3: ledger the cash portion of this balance payment.
                    if ($totalCash > 0) {
                        $this->recordCashLedger(
                            $register,
                            'sale',
                            'cash',
                            (float) $totalCash,
                            (float) $register->expected_cash + (float) $totalCash,
                            $order->id,
                            $request->user()->id,
                        );
                    }
                }
            }

            // Determine whether any payment in this batch requires approval.
            // If so, the order CANNOT be marked as paid or completed regardless
            // of how much money was submitted.
            // Cash-type methods (by code or DB type) never require approval.
            $anyNeedsApproval = collect($paymentsInput)->contains(
                fn ($pmt) => !($isCashType($pmt['method'] ?? 'other')
                    || in_array($pmt['method'] ?? 'other', ['mpesa', 'card', 'card_paystack']))
            );

            // Also check any previously recorded pending approval payments on this order
            $existingPendingApproval = DB::table('payments')
                ->where('order_id', $order->id)
                ->where('requires_approval', true)
                ->where('approval_status', 'pending_review')
                ->exists();

            $hasPendingApproval = $anyNeedsApproval || $existingPendingApproval;

            if ($hasPendingApproval) {
                // Order stays in processing with payment_status = pending_approval
                // It will only advance once all payments are approved.
                $newPayStatus = 'pending_approval';
                $newStatus    = 'processing';
            } else {
                $newPayStatus = $isDeposit ? 'deposit' : ($totalPaying >= $order->total_amount - 0.01 ? 'paid' : 'partial');
                // Payment confirmed → 'confirmed' (not 'completed').
                // 'confirmed' means: payment received, order is real, fulfilment can begin.
                // 'completed' is set manually by staff after goods are handed over / shipped.
                $newStatus = match($newPayStatus) {
                    'paid'    => 'confirmed',
                    default   => 'processing',
                };
            }

            // Attach proof file inside the transaction so a storage failure rolls back cleanly.
            $hasProof = false;
            if ($request->hasFile('proof_of_payment') && $proofPaymentId) {
                $path = $request->file('proof_of_payment')->store("payment-proofs/{$order->id}", 'private');
                DB::table('payments')->where('id', $proofPaymentId)->update([
                    'proof_of_payment_path' => $path,
                    'proof_uploaded_at'     => now(),
                    'requires_approval'     => true,
                    'approval_status'       => 'pending_review',
                    'status'                => 'pending',
                    'updated_at'            => now(),
                ]);
                // When a proof is attached the order must stay on hold regardless
                // of what newPayStatus was computed above.
                $newPayStatus = 'pending_approval';
                $newStatus    = 'processing';
                $hasPendingApproval = true;
                $hasProof     = true;
            }

            $order->update([
                'payment_status' => $newPayStatus,
                'status'         => $newStatus,
                'payment_method' => $paymentsInput[0]['method'] ?? 'other',
                'completed_at'   => null,  // set by staff at handover, not by payment
                'deposit_amount' => $isDeposit ? $depositAmt : $order->deposit_amount,
            ]);

            DB::commit();

            try { NotificationService::orderPlaced($order->id, $order->order_number, $order->outlet_id); } catch (\Exception) {}

            // ── Per-payment notifications ─────────────────────────────────────
            // Cash / cash-type → notify admin immediately (for reconciliation).
            // Non-automated (bank_transfer, other…) → notify admin approval queue.
            $latestPayments = $order->fresh(['payments'])->payments->sortByDesc('id')->values();
            $paymentsByMethod = collect($paymentsInput)->map(function ($pmt, $i) use ($latestPayments) {
                return ['input' => $pmt, 'model' => $latestPayments->get($i)];
            });
            foreach ($paymentsByMethod as $entry) {
                $pmtMethod = $entry['input']['method'] ?? 'other';
                $pmtModel  = $entry['model'];
                if (!$pmtModel) continue;
                try {
                    if ($isCashType($pmtMethod)) {
                        // Cash and cash-type methods: payment is immediate — notify admin
                        // for reconciliation purposes, same as the standard cash flow.
                        NotificationService::paymentReceived(
                            $pmtModel->id,
                            $pmtModel->payment_number,
                            $order->id,
                            $order->order_number,
                            (float) $entry['input']['amount'],
                            $order->currency_code,
                            $pmtMethod
                        );
                    } elseif ($hasPendingApproval && !in_array($pmtMethod, ['mpesa', 'card', 'card_paystack'])) {
                        NotificationService::paymentApprovalRequired(
                            $pmtModel->id,
                            $pmtModel->payment_number,
                            $order->id,
                            $order->order_number,
                            (float) $entry['input']['amount'],
                            $order->currency_code,
                            $order->customer_country_code ?? ''
                        );
                    }
                } catch (\Exception) {}
            }

            try {
                ActivityLogService::log('pos_payment_recorded', $order, [
                    'order_number'    => $order->order_number,
                    'outlet_id'       => $order->outlet_id,
                    'amount_paid'     => $totalPaying,
                    'payment_methods' => collect($paymentsInput)->pluck('method')->unique()->values(),
                    'payment_status'  => $newPayStatus,
                    'is_deposit'      => $isDeposit,
                    'needs_approval'  => $hasPendingApproval,
                ]);
            } catch (\Exception) {}

            $needsApprovalResponse = $hasPendingApproval || $newPayStatus === 'pending_approval';
            $message = match(true) {
                $needsApprovalResponse => 'Payment submitted and awaiting admin approval. Order is on hold.',
                $newPayStatus === 'paid' => 'Payment recorded. Sale complete.',
                default => 'Payment recorded.',
            };

            return response()->json([
                'message'        => $message,
                'order'          => $this->transformSaleOrder($order->fresh(['items', 'payments'])),
                'change'         => round($primaryChange, 2),
                'payment_status' => $newPayStatus,
                'needs_approval' => $needsApprovalResponse,
                'proof_uploaded' => $hasProof,
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('POS recordPosPay failed', ['order_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to record payment. Please try again.'], 500);
        }
    }

    private function transformSaleOrder(Order $order): array
    {
        $customerName = trim(
            ($order->customer_first_name ?? '') . ' ' . ($order->customer_last_name ?? '')
        ) ?: null;

        // Resolve cashier name from the user who created the order
        $cashierName = null;
        if ($order->created_by) {
            $cashier = \App\Models\User::find($order->created_by, ['id', 'first_name', 'last_name']);
            if ($cashier) {
                $cashierName = trim("{$cashier->first_name} {$cashier->last_name}") ?: null;
            }
        }

        // Resolve cash payment details (tendered / change) from payments relation
        $cashReceived   = null;
        $changeGiven    = null;
        $registerNumber = null;
        if ($order->relationLoaded('payments')) {
            $cashPmt = $order->payments->first(fn ($p) => $p->payment_method === 'cash');
            if ($cashPmt) {
                $cashReceived = $cashPmt->cash_received ? (float) $cashPmt->cash_received : null;
                $changeGiven  = $cashPmt->change_given  ? (float) $cashPmt->change_given  : null;
            }
        }

        // Resolve register for the cashier who created this order
        if ($order->outlet_id && $order->created_by) {
            $reg = CashRegister::where('outlet_id', $order->outlet_id)
                ->where('opened_by', $order->created_by)
                ->where('status', 'open')
                ->latest('opened_at')
                ->first(['id', 'register_name']);
            $registerNumber = $reg?->id ?? null;
        }

        return [
            'id'                => $order->id,
            'order_number'      => $order->order_number,
            'outlet_id'         => $order->outlet_id,
            'outlet_name'       => $order->outlet?->name,
            // FIX 3: expose customer_id so frontend can restore the exact DB link
            'customer_id'       => $order->customer_id ?? null,
            'customer_name'     => $customerName,
            'customer_phone'    => $order->customer_phone,
            'customer_email'    => $order->customer_email,
            'cashier_name'      => $cashierName,
            'register_number'   => $registerNumber,
            'items'             => $order->items->map(fn ($i) => [
                'id'               => $i->id,
                'product_id'       => $i->product_id,
                'variant_id'       => $i->product_variant_id,
                'product_name'     => $i->product_name,
                'variant_name'     => $i->variant_name,
                'sku'              => $i->sku,
                'quantity'         => $i->quantity,
                'unit_price'       => (float) $i->unit_price,
                // FIX 1: return raw discount type + value — critical for lossless restore
                'discount_type'    => $i->discount_type ?? 'none',
                'discount_value'   => (float) ($i->discount_value ?? 0),
                'discount_amount'  => (float) $i->discount_amount,
                'tax_amount'       => (float) $i->tax_amount,
                'tax_rate'         => $i->product_id
                    ? round(TaxCalculationService::rateForProduct($i->product_id) * 100, 4)
                    : 0.0,
                'tax_name'         => $i->product_id
                    ? (TaxCalculationService::rateLabelForProduct($i->product_id) ?: null)
                    : null,
                'subtotal'         => (float) $i->total_price,
                // MTO restore — persisted as '__MTO__[|notes]' in the notes column
                'is_production'    => isset($i->notes) && str_starts_with($i->notes, '__MTO__'),
                'production_notes' => (isset($i->notes) && str_starts_with($i->notes, '__MTO__') && str_contains($i->notes, '|'))
                    ? substr($i->notes, strpos($i->notes, '|') + 1)
                    : null,
                // FIX 4: return structured measurement values for lossless MTO restore
                'measurement_values' => $i->measurement_values
                    ? (is_string($i->measurement_values)
                        ? json_decode($i->measurement_values, true)
                        : $i->measurement_values)
                    : null,
            ])->values(),
            // Payments with approval status — used by FullInvoice and ThermalReceipt
            'payments' => $order->relationLoaded('payments')
                ? $order->payments->map(fn ($p) => [
                    'id'                 => $p->id,
                    'payment_method'     => $p->payment_method,
                    'amount'             => (float) $p->amount,
                    'currency_code'      => $p->currency_code,
                    'status'             => $p->status,
                    'approval_status'    => $p->approval_status,
                    'requires_approval'  => (bool) $p->requires_approval,
                    'provider_reference' => $p->provider_reference,
                    'reference'          => $p->provider_reference,
                    'cash_received'      => $p->cash_received ? (float) $p->cash_received : null,
                    'change_given'       => $p->change_given  ? (float) $p->change_given  : null,
                    'paid_at'            => $p->paid_at?->toIso8601String(),
                ])->values()
                : [],
            'subtotal'           => (float) $order->subtotal,
            'discount_amount'    => (float) $order->discount_amount,
            // FIX 2: return raw cart discount type + value for lossless restore
            'cart_discount_type'  => $order->cart_discount_type  ?? 'none',
            'cart_discount_value' => (float) ($order->cart_discount_value ?? 0),
            'tax_amount'          => (float) $order->tax_amount,
            'total'               => (float) $order->total_amount,
            'currency_code'       => $order->currency_code ?? 'KES',
            'prices_include_tax'  => (bool) ($order->prices_include_tax ?? false),
            'payment_method'      => $order->payment_method,
            'payment_status'      => $order->payment_status,
            'payment_reference'   => null,
            'cash_received'       => $cashReceived,
            'change_given'        => $changeGiven,
            'status'              => $order->status,
            'notes'               => $order->notes,
            'created_at'          => $order->created_at->toIso8601String(),
        ];
    }
}
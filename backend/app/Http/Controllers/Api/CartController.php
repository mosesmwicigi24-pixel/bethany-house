<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Models\Coupon;
use App\Services\TaxCalculationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CartController extends Controller
{
    /**
     * Get customer's cart
     */
    public function index(Request $request)
    {
        $customer = $request->user()->customer;

        if (!$customer) {
            return response()->json([
                'message' => 'Customer profile not found',
            ], 404);
        }

        $cart = Cart::with([
            'items.variant.product.images',
            'items.variant.inventories',
            'coupon'
        ])->firstOrCreate([
            'customer_id' => $customer->id,
        ]);

        // Calculate totals
        $currency = $customer->preferred_currency ?? 'USD';
        $priceField = 'price_' . strtolower($currency);
        
        $subtotal = 0;
        foreach ($cart->items as $item) {
            $subtotal += $item->variant->$priceField * $item->quantity;
        }

        $discount = 0;
        if ($cart->coupon) {
            $discount = $this->calculateDiscount($cart->coupon, $subtotal);
        }

        // Calculate tax per item using TaxCalculationService
        $taxInclusive = TaxCalculationService::isTaxInclusive();
        $taxTotal = 0;
        foreach ($cart->items as $item) {
            $productId = $item->variant?->product_id ?? 0;
            $unitPrice = $item->variant->$priceField ?? 0;
            $line = TaxCalculationService::calculateLine($unitPrice, $item->quantity, $productId, $taxInclusive);
            $taxTotal += $line['tax_amount'];
        }
        $tax   = round($taxTotal, 2);
        $total = $taxInclusive
            ? round($subtotal - $discount, 2)          // tax already inside price
            : round($subtotal - $discount + $tax, 2);  // tax added on top

        return response()->json([
            'cart' => $cart,
            'summary' => [
                'currency' => $currency,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => $tax,
                'total' => $total,
                'items_count' => $cart->items->sum('quantity'),
            ],
        ]);
    }

    /**
     * Add item to cart
     */
    public function addItem(Request $request)
    {
        $validated = $request->validate([
            'variant_id' => 'required|exists:product_variants,id',
            'quantity' => 'required|integer|min:1',
        ]);

        $customer = $request->user()->customer;

        if (!$customer) {
            return response()->json([
                'message' => 'Customer profile not found',
            ], 404);
        }

        // Check if variant exists and has stock
        $variant = ProductVariant::with('product')->findOrFail($validated['variant_id']);

        // Check inventory availability
        $availableStock = DB::table('inventories')
            ->where('variant_id', $variant->id)
            ->where('location_type', 'warehouse')
            ->sum('quantity');

        if ($availableStock < $validated['quantity']) {
            return response()->json([
                'message' => 'Insufficient stock available',
                'available_stock' => $availableStock,
            ], 422);
        }

        // Get or create cart
        $cart = Cart::firstOrCreate([
            'customer_id' => $customer->id,
        ]);

        // Check if item already exists in cart
        $cartItem = CartItem::where('cart_id', $cart->id)
            ->where('variant_id', $variant->id)
            ->first();

        if ($cartItem) {
            // Update quantity
            $newQuantity = $cartItem->quantity + $validated['quantity'];
            
            if ($availableStock < $newQuantity) {
                return response()->json([
                    'message' => 'Cannot add more items. Insufficient stock.',
                    'available_stock' => $availableStock,
                    'current_in_cart' => $cartItem->quantity,
                ], 422);
            }

            $cartItem->update(['quantity' => $newQuantity]);
        } else {
            // Create new cart item
            $cartItem = CartItem::create([
                'cart_id' => $cart->id,
                'variant_id' => $variant->id,
                'quantity' => $validated['quantity'],
            ]);
        }

        $cart->load(['items.variant.product.images']);

        return response()->json([
            'message' => 'Item added to cart successfully',
            'cart' => $cart,
            'item' => $cartItem->load('variant.product.images'),
        ], 201);
    }

    /**
     * Update cart item quantity
     */
    public function updateItem(Request $request, $id)
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $customer = $request->user()->customer;
        $cart = Cart::where('customer_id', $customer->id)->firstOrFail();

        $cartItem = CartItem::where('cart_id', $cart->id)
            ->where('id', $id)
            ->firstOrFail();

        // Check inventory availability
        $availableStock = DB::table('inventories')
            ->where('variant_id', $cartItem->variant_id)
            ->where('location_type', 'warehouse')
            ->sum('quantity');

        if ($availableStock < $validated['quantity']) {
            return response()->json([
                'message' => 'Insufficient stock available',
                'available_stock' => $availableStock,
            ], 422);
        }

        $cartItem->update(['quantity' => $validated['quantity']]);

        return response()->json([
            'message' => 'Cart item updated successfully',
            'item' => $cartItem->load('variant.product.images'),
        ]);
    }

    /**
     * Remove item from cart
     */
    public function removeItem($id)
    {
        $customer = request()->user()->customer;
        $cart = Cart::where('customer_id', $customer->id)->firstOrFail();

        $cartItem = CartItem::where('cart_id', $cart->id)
            ->where('id', $id)
            ->firstOrFail();

        $cartItem->delete();

        return response()->json([
            'message' => 'Item removed from cart successfully',
        ]);
    }

    /**
     * Clear entire cart
     */
    public function clear()
    {
        $customer = request()->user()->customer;
        $cart = Cart::where('customer_id', $customer->id)->firstOrFail();

        $cart->items()->delete();
        $cart->update(['coupon_id' => null]);

        return response()->json([
            'message' => 'Cart cleared successfully',
        ]);
    }

    /**
     * Apply coupon to cart
     */
    public function applyCoupon(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string',
        ]);

        $customer = $request->user()->customer;
        $cart = Cart::with('items.variant')->where('customer_id', $customer->id)->firstOrFail();

        // Find coupon
        $coupon = Coupon::where('code', $validated['code'])
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', now());
            })
            ->first();

        if (!$coupon) {
            return response()->json([
                'message' => 'Invalid or expired coupon code',
            ], 422);
        }

        // Check usage limit
        if ($coupon->usage_limit && $coupon->times_used >= $coupon->usage_limit) {
            return response()->json([
                'message' => 'Coupon usage limit reached',
            ], 422);
        }

        // Check customer usage limit
        if ($coupon->usage_limit_per_customer) {
            $customerUsage = DB::table('orders')
                ->where('customer_id', $customer->id)
                ->where('coupon_id', $coupon->id)
                ->count();

            if ($customerUsage >= $coupon->usage_limit_per_customer) {
                return response()->json([
                    'message' => 'You have already used this coupon the maximum number of times',
                ], 422);
            }
        }

        // Calculate cart subtotal
        $currency = $customer->preferred_currency ?? 'USD';
        $priceField = 'price_' . strtolower($currency);
        
        $subtotal = 0;
        foreach ($cart->items as $item) {
            $subtotal += $item->variant->$priceField * $item->quantity;
        }

        // Check minimum order amount
        if ($coupon->minimum_order_amount && $subtotal < $coupon->minimum_order_amount) {
            return response()->json([
                'message' => "Minimum order amount of {$coupon->minimum_order_amount} required",
            ], 422);
        }

        // Apply coupon
        $cart->update(['coupon_id' => $coupon->id]);

        $discount = $this->calculateDiscount($coupon, $subtotal);

        return response()->json([
            'message' => 'Coupon applied successfully',
            'coupon' => $coupon,
            'discount' => $discount,
        ]);
    }

    /**
     * Remove coupon from cart
     */
    public function removeCoupon()
    {
        $customer = request()->user()->customer;
        $cart = Cart::where('customer_id', $customer->id)->firstOrFail();

        $cart->update(['coupon_id' => null]);

        return response()->json([
            'message' => 'Coupon removed successfully',
        ]);
    }

    /**
     * Calculate discount amount based on coupon
     */
    private function calculateDiscount($coupon, $subtotal)
    {
        if ($coupon->type === 'fixed') {
            return min($coupon->value, $subtotal);
        } else {
            // Percentage
            $discount = ($subtotal * $coupon->value) / 100;
            
            if ($coupon->max_discount_amount) {
                $discount = min($discount, $coupon->max_discount_amount);
            }
            
            return $discount;
        }
    }

    /**
     * Sync cart items (for guest to logged-in user migration)
     */
    public function sync(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.variant_id' => 'required|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        $customer = $request->user()->customer;

        if (!$customer) {
            return response()->json([
                'message' => 'Customer profile not found',
            ], 404);
        }

        // Get or create cart
        $cart = Cart::firstOrCreate([
            'customer_id' => $customer->id,
        ]);

        DB::beginTransaction();
        try {
            // Clear existing items
            $cart->items()->delete();

            // Add new items
            foreach ($validated['items'] as $itemData) {
                CartItem::create([
                    'cart_id' => $cart->id,
                    'variant_id' => $itemData['variant_id'],
                    'quantity' => $itemData['quantity'],
                ]);
            }

            DB::commit();

            $cart->load(['items.variant.product.images']);

            return response()->json([
                'message' => 'Cart synced successfully',
                'cart' => $cart,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to sync cart',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
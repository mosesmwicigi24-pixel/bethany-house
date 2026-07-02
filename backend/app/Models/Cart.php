<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'user_id',
        'session_id',
        'cart_type',
        'currency_code',
        'status',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'shipping_amount',
        'total_amount',
        'coupon_code',
        'last_activity_at',
        'abandoned_at',
        'converted_at',
        'expires_at',
        'notes',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'shipping_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'last_activity_at' => 'datetime',
        'abandoned_at' => 'datetime',
        'converted_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Relationships
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
                     ->where(function($q) {
                         $q->whereNull('expires_at')
                           ->orWhere('expires_at', '>', now());
                     });
    }

    public function scopeAbandoned($query)
    {
        return $query->where('status', 'abandoned')
                     ->orWhere(function($q) {
                         $q->where('status', 'active')
                           ->where('last_activity_at', '<', now()->subHours(24));
                     });
    }

    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }

    public function scopeShopping($query)
    {
        return $query->where('cart_type', 'shopping');
    }

    public function scopeWishlist($query)
    {
        return $query->where('cart_type', 'wishlist');
    }

    /**
     * Helper methods
     */
    public function updateActivity()
    {
        $this->update(['last_activity_at' => now()]);
        return $this;
    }

    public function addItem($productId, $variantId = null, $quantity = 1, $unitPrice = null)
    {
        // Check if item already exists
        $item = $this->items()
            ->where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->first();

        if ($item) {
            $item->increment('quantity', $quantity);
        } else {
            $item = $this->items()->create([
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
            ]);
        }

        $this->updateTotals();
        $this->updateActivity();

        return $item;
    }

    public function updateItemQuantity($itemId, $quantity)
    {
        $item = $this->items()->find($itemId);
        
        if (!$item) {
            return false;
        }

        if ($quantity <= 0) {
            return $this->removeItem($itemId);
        }

        $item->update(['quantity' => $quantity]);
        $this->updateTotals();
        $this->updateActivity();

        return $item;
    }

    public function removeItem($itemId)
    {
        $deleted = $this->items()->where('id', $itemId)->delete();
        
        if ($deleted) {
            $this->updateTotals();
            $this->updateActivity();
        }

        return $deleted;
    }

    public function clear()
    {
        $this->items()->delete();
        $this->updateTotals();
        
        return $this;
    }

    public function updateTotals()
    {
        $subtotal = 0;

        foreach ($this->items as $item) {
            $price = $item->unit_price;
            
            if (!$price && $item->product) {
                $productPrice = $item->product->getPriceForCurrency(
                    $this->currency_code,
                    $item->product_variant_id
                );
                
                if ($productPrice) {
                    $price = $productPrice->getEffectivePrice();
                    $item->update(['unit_price' => $price]);
                }
            }

            if ($price) {
                $subtotal += $price * $item->quantity;
            }
        }

        $this->subtotal = $subtotal;
        $this->total_amount = $subtotal - $this->discount_amount + $this->tax_amount + $this->shipping_amount;
        $this->save();

        return $this;
    }

    public function applyCoupon($couponCode)
    {
        // This would integrate with a Coupon model if you have one
        // For now, just store the code
        $this->update(['coupon_code' => $couponCode]);
        
        return $this;
    }

    public function getTotalItems()
    {
        return $this->items()->sum('quantity');
    }

    public function isEmpty()
    {
        return $this->items()->count() === 0;
    }

    public function markAsAbandoned()
    {
        $this->update([
            'status' => 'abandoned',
            'abandoned_at' => now(),
        ]);

        return $this;
    }

    public function markAsConverted()
    {
        $this->update([
            'status' => 'converted',
            'converted_at' => now(),
        ]);

        return $this;
    }

    public function convertToOrder()
    {
        // This would create an order from the cart
        // Implementation depends on your Order creation logic
        
        $this->markAsConverted();
        
        return $this;
    }

    public function mergeTo(Cart $targetCart)
    {
        foreach ($this->items as $item) {
            $targetCart->addItem(
                $item->product_id,
                $item->product_variant_id,
                $item->quantity,
                $item->unit_price
            );
        }

        $this->delete();

        return $targetCart;
    }

    public function clone()
    {
        $newCart = static::create([
            'customer_id' => $this->customer_id,
            'user_id' => $this->user_id,
            'currency_code' => $this->currency_code,
            'cart_type' => $this->cart_type,
        ]);

        foreach ($this->items as $item) {
            $newCart->items()->create([
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
            ]);
        }

        $newCart->updateTotals();

        return $newCart;
    }
}

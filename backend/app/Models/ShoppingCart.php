<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShoppingCart extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'session_id',
        'currency_code',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(CartItem::class, 'cart_id');
    }

    public function scopeActive($query)
    {
        return $query->where('expires_at', '>', now())->orWhereNull('expires_at');
    }

    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }

    public function getTotalItems()
    {
        return $this->items()->sum('quantity');
    }

    public function getSubtotal()
    {
        $subtotal = 0;
        foreach ($this->items as $item) {
            $price = $item->product->getPriceForCurrency($this->currency_code, $item->product_variant_id);
            if ($price) {
                $subtotal += $price->getEffectivePrice() * $item->quantity;
            }
        }
        return $subtotal;
    }

    public function addItem($productId, $variantId = null, $quantity = 1)
    {
        $item = $this->items()
            ->where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->first();

        if ($item) {
            $item->increment('quantity', $quantity);
        } else {
            $this->items()->create([
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'quantity' => $quantity,
            ]);
        }

        return $this;
    }

    public function removeItem($itemId)
    {
        return $this->items()->where('id', $itemId)->delete();
    }

    public function clear()
    {
        return $this->items()->delete();
    }
}

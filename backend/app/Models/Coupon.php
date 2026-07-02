<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Coupon extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code', 'description', 'type', 'value',
        'minimum_order_amount', 'max_discount_amount',
        'is_active', 'valid_from', 'valid_until',
        'usage_limit', 'usage_limit_per_customer', 'times_used',
        'applicable_products', 'applicable_categories', 'created_by',
    ];

    protected $casts = [
        'value'                  => 'decimal:2',
        'minimum_order_amount'   => 'decimal:2',
        'max_discount_amount'    => 'decimal:2',
        'is_active'              => 'boolean',
        'valid_from'             => 'datetime',
        'valid_until'            => 'datetime',
        'applicable_products'    => 'array',
        'applicable_categories'  => 'array',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($coupon) {
            if (empty($coupon->code)) {
                $coupon->code = strtoupper(Str::random(8));
            }
            $coupon->code = strtoupper($coupon->code);
        });
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(fn($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
            ->where(fn($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()));
    }

    public function isExpired(): bool
    {
        return $this->valid_until && $this->valid_until->isPast();
    }

    public function isExhausted(): bool
    {
        return $this->usage_limit && $this->times_used >= $this->usage_limit;
    }

    public function getStatusAttribute(): string
    {
        if (!$this->is_active) return 'inactive';
        if ($this->isExpired()) return 'expired';
        if ($this->isExhausted()) return 'exhausted';
        if ($this->valid_from && $this->valid_from->isFuture()) return 'scheduled';
        return 'active';
    }

    public function calculateDiscount(float $subtotal): float
    {
        $discount = match ($this->type) {
            'fixed'        => min($this->value, $subtotal),
            'percentage'   => $subtotal * ($this->value / 100),
            'free_shipping'=> 0,
            default        => 0,
        };

        if ($this->max_discount_amount && $discount > $this->max_discount_amount) {
            $discount = $this->max_discount_amount;
        }

        return round($discount, 2);
    }
}
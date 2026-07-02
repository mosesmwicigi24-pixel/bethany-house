<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'decimal_places',
        'exchange_rate',
        'symbol_position',
        'thousand_separator',
        'decimal_separator',
        'is_base',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'decimal_places' => 'integer',
        'exchange_rate'  => 'decimal:6',
        'is_base'        => 'boolean',
        'is_default'     => 'boolean',
        'is_active'      => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    /**
     * Countries that use this as their default currency.
     */
    public function countries()
    {
        return $this->hasMany(\App\Models\Country::class, 'default_currency_code', 'code');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeBase($query)
    {
        return $query->where('is_base', true);
    }

    public function convert($amount, Currency $toCurrency)
    {
        if ($this->code === $toCurrency->code) {
            return $amount;
        }

        // Convert to base currency first, then to target currency
        $baseAmount = $amount / $this->exchange_rate;
        return $baseAmount * $toCurrency->exchange_rate;
    }

    public function format($amount)
    {
        return $this->symbol . number_format($amount, $this->decimal_places);
    }
}
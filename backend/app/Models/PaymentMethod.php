<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'provider',
        'is_active',
        'supported_currencies',
        'configuration',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'supported_currencies' => 'array',
        'configuration' => 'array',
        'sort_order' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    public function supportsCurrency($currencyCode)
    {
        if (!$this->supported_currencies) {
            return true; // If not specified, assume all currencies
        }

        return in_array($currencyCode, $this->supported_currencies);
    }

    public function getConfigValue($key, $default = null)
    {
        return $this->configuration[$key] ?? $default;
    }
}

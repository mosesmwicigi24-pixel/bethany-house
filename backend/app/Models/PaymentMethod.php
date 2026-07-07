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
        'requires_approval',
        'supported_currencies',
        'configuration',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'requires_approval' => 'boolean',
        'supported_currencies' => 'array',
        'configuration' => 'array',
        'sort_order' => 'integer',
    ];

    /**
     * Single source of truth for "does a payment made with this method need admin
     * approval before it counts?". Used by every payment-recording path
     * (createSale, recordPosPay, addPayment) and mirrored to the client so the
     * frontend and backend can never disagree.
     *
     * The per-method `requires_approval` flag is authoritative when set. When it
     * is NULL (un-configured) we fall back to the legacy type derivation so old
     * rows behave exactly as before: cash + gateway rails settle instantly,
     * everything else is held for review.
     *
     * @param  string       $code            payment method code (e.g. 'inmpaybill')
     * @param  string|null  $type            the method's DB `type`, if known
     * @param  mixed        $configuredFlag  the method's `requires_approval` column (bool|null)
     */
    public static function deriveRequiresApproval(string $code, ?string $type = null, $configuredFlag = null): bool
    {
        // Explicit per-method configuration wins.
        if ($configuredFlag !== null) {
            return (bool) $configuredFlag;
        }

        // Legacy fallback — instant/gateway settlement needs no approval.
        $automated = $code === 'cash'
            || $type === 'cash'
            || in_array($code, ['mpesa', 'card', 'card_paystack', 'card_flutterwave', 'paystack', 'flutterwave'], true)
            || in_array($type, ['mobile_money', 'card', 'wallet'], true);

        return !$automated;
    }

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

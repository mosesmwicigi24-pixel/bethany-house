<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number',
        'user_id',
        'outlet_id',
        'order_type',
        'status',
        'currency_code',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'prices_include_tax',
        'shipping_amount',
        'shipping_fee_overridden',
        'shipping_fee_note',
        'total_amount',
        'deposit_amount',
        'balance_due_date',
        'customer_email',
        'customer_phone',
        'customer_first_name',
        'customer_last_name',
        'customer_country_code',
        'billing_address_line1',
        'billing_address_line2',
        'billing_city',
        'billing_state',
        'billing_postal_code',
        'billing_country_code',
        'shipping_address_line1',
        'shipping_address_line2',
        'shipping_city',
        'shipping_state',
        'shipping_postal_code',
        'shipping_country_code',
        'shipping_method',
        'delivery_type',
        'pickup_outlet_id',
        'payment_method',
        'payment_status',
        'notes',
        'customer_notes',
        'ip_address',
        'user_agent',
        'completed_at',
        'cancelled_at',
        'payment_token',
        'payment_token_expires_at',
        'is_international',
        'created_by',
    ];

    protected $casts = [
        'subtotal'               => 'decimal:2',
        'discount_amount'        => 'decimal:2',
        'tax_amount'             => 'decimal:2',
        'shipping_amount'        => 'decimal:2',
        'deposit_amount'         => 'decimal:2',
        'total_amount'           => 'decimal:2',
        'prices_include_tax'     => 'boolean',
        'shipping_fee_overridden'=> 'boolean',
        'balance_due_date'       => 'date',
        'completed_at'           => 'datetime',
        'cancelled_at'           => 'datetime',
        'payment_token_expires_at' => 'datetime',
    ];

    protected $appends = ['customer_name'];

    /**
     * Computed accessor — returns a display name for the customer regardless
     * of whether a User account is linked or just denormalised name columns
     * are stored on the order (e.g. POS walk-in customers).
     */
    public function getCustomerNameAttribute(): ?string
    {
        if ($this->user) {
            return trim($this->user->first_name . ' ' . $this->user->last_name) ?: null;
        }
        return trim(($this->customer_first_name ?? '') . ' ' . ($this->customer_last_name ?? '')) ?: null;
    }

    /**
     * Relationships
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function pickupOutlet()
    {
        return $this->belongsTo(Outlet::class, 'pickup_outlet_id');
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistory()
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function shipments()
    {
        return $this->hasMany(OrderShipment::class);
    }

    public function returns()
    {
        return $this->hasMany(OrderReturn::class);
    }

    /**
     * Scopes
     */
    public function scopeOnline($query)
    {
        return $query->where('order_type', 'online');
    }

    public function scopePos($query)
    {
        return $query->where('order_type', 'pos');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopePaid($query)
    {
        return $query->where('payment_status', 'paid');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Helper methods
     */
    public function isPaid()
    {
        return $this->payment_status === 'paid';
    }

    public function isCompleted()
    {
        return $this->status === 'completed';
    }

    public function isCancelled()
    {
        return $this->status === 'cancelled';
    }

    public function canBeCancelled()
    {
        return in_array($this->status, ['pending', 'processing']) && !$this->cancelled_at;
    }
    /**
     * Is this an international order (outside Kenya)?
     */
    public function isInternational(): bool
    {
        return !empty($this->customer_country_code)
            && strtoupper($this->customer_country_code) !== 'KE';
    }

    /**
     * Resolve the correct currency for an order based on destination country.
     * Kenya (KE) → KES, everywhere else → USD.
     */
    public static function resolveCurrency(string $countryCode): string
    {
        return strtoupper($countryCode) === 'KE' ? 'KES' : 'USD';
    }

    /**
     * Net amount collected across all settled payment records — gross paid minus
     * anything refunded back. Voided payments (status != 'paid') don't count at
     * all; refunded ones count only for the un-refunded remainder. This makes
     * void/refund reconcile correctly (audit MON-1): a fully-refunded order nets
     * to 0 and syncPaymentStatus() moves it back to 'pending'.
     */
    public function totalPaid(): float
    {
        return (float) $this->payments()
            ->where('status', 'paid')
            ->selectRaw('COALESCE(SUM(amount - refund_amount), 0) AS net')
            ->value('net');
    }

    /**
     * Amount still outstanding.
     */
    public function outstandingBalance(): float
    {
        return max(0, (float) $this->total_amount - $this->totalPaid());
    }

    /**
     * Re-evaluate and persist payment_status based on current payments.
     * Call after every payment is recorded or voided.
     */
    public function syncPaymentStatus(): void
    {
        $paid      = $this->totalPaid();
        $total     = (float) $this->total_amount;
        $isDeposit = !is_null($this->deposit_amount) && $paid > 0 && $paid < $total;

        if ($paid <= 0) {
            $status = 'pending';
        } elseif ($isDeposit) {
            $status = 'deposit';
        } elseif ($paid < $total) {
            $status = 'partial';
        } else {
            $status = 'paid';
        }

        $this->update(['payment_status' => $status]);
    }

}
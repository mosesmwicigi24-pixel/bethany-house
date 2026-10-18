<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One payment to a supplier against a purchase order (see the
 * create_supplier_payments migration). Recorded once; afterwards only the
 * void fields may change, once. Never deleted.
 */
class SupplierPayment extends Model
{
    public const METHODS = ['bank_transfer', 'mpesa', 'cash', 'cheque', 'card', 'other'];

    /** Methods that leave a trail the reference must name. */
    public const NEEDS_REFERENCE = ['bank_transfer', 'mpesa', 'cheque', 'card'];

    private const VOID_FIELDS = ['voided_at', 'voided_by', 'void_reason', 'updated_at'];

    protected $fillable = [
        'payment_number', 'purchase_order_id', 'supplier_id', 'amount', 'currency_code',
        'rate_to_kes', 'amount_kes', 'method', 'reference', 'paid_at', 'notes',
        'client_request_id', 'recorded_by', 'voided_at', 'voided_by', 'void_reason',
    ];

    protected $casts = [
        'amount'      => 'decimal:2',
        'rate_to_kes' => 'decimal:6',
        'amount_kes'  => 'decimal:2',
        'paid_at'     => 'datetime',
        'voided_at'   => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $p) {
            if (empty($p->payment_number)) {
                $prefix = 'SPY-' . now()->format('Ymd') . '-';
                $n = static::where('payment_number', 'like', $prefix . '%')->count() + 1;
                $p->payment_number = $prefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            }
        });

        static::updating(function (self $p) {
            $changed = array_keys($p->getDirty());
            if (array_diff($changed, self::VOID_FIELDS) || $p->getOriginal('voided_at') !== null) {
                throw new LogicException('A supplier payment is never edited: void it and record it again.');
            }
        });

        static::deleting(function () {
            throw new LogicException('A supplier payment is never deleted: void it.');
        });
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function voider()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function scopeStanding($q)
    {
        return $q->whereNull('voided_at');
    }
}

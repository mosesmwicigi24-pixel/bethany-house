<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One decision the automated reorder-ping job made about a due
 * (customer, product) pair: sent, failed, or skipped and why.
 * phone is canonical E.164 digits (no '+') via App\Support\Phone.
 */
class ReplenishmentPing extends Model
{
    public const STATUS_SENT             = 'sent';
    public const STATUS_FAILED           = 'failed';
    public const STATUS_SKIPPED_COOLDOWN = 'skipped_cooldown';
    public const STATUS_SKIPPED_OPTOUT   = 'skipped_optout';
    public const STATUS_SKIPPED_NO_PHONE = 'skipped_no_phone';

    protected $fillable = [
        'phone',
        'customer_id',
        'product_id',
        'product_name',
        'cycle_days',
        'days_over',
        'avg_value',
        'status',
        'wamid',
        'error',
    ];

    protected $casts = [
        'cycle_days' => 'integer',
        'days_over'  => 'integer',
        'avg_value'  => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

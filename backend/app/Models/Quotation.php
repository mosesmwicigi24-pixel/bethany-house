<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A priced offer at the front of the sales-document lifecycle
 * (quotation → invoice → receipt). See App\Services\DocumentNumberService and the
 * sales_documents ledger for issued-artifact tracking.
 */
class Quotation extends Model
{
    use HasFactory;

    // Statuses.
    public const DRAFT     = 'draft';
    public const SENT      = 'sent';
    public const ACCEPTED  = 'accepted';
    public const DECLINED  = 'declined';
    public const EXPIRED   = 'expired';
    public const CONVERTED = 'converted';

    protected $fillable = [
        'quote_number',
        'user_id',
        'outlet_id',
        'source',
        'status',
        'currency_code',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'shipping_amount',
        'total_amount',
        'customer_email',
        'customer_phone',
        'customer_first_name',
        'customer_last_name',
        'served_by',
        'valid_until',
        'notes',
        'terms',
        'converted_order_id',
        'issued_at',
        'accepted_at',
        'created_by',
        'quote_token',
        'quote_token_expires_at',
    ];

    protected $casts = [
        'subtotal'        => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount'      => 'decimal:2',
        'shipping_amount' => 'decimal:2',
        'total_amount'    => 'decimal:2',
        'valid_until'     => 'date',
        'issued_at'       => 'datetime',
        'accepted_at'     => 'datetime',
        'quote_token_expires_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function convertedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'converted_order_id');
    }

    /** The issued-document ledger rows for this quotation (its QUO artifacts). */
    public function documents()
    {
        return $this->morphMany(SalesDocument::class, 'documentable');
    }

    /** The INVOICE this quotation became, once accepted/converted (else null). */
    public function invoiceDocument()
    {
        return $this->hasOne(SalesDocument::class, 'documentable_id', 'converted_order_id')
            ->where('documentable_type', Order::class)
            ->where('type', SalesDocument::INVOICE);
    }

    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast();
    }
}

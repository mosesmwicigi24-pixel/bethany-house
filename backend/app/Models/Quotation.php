<?php

namespace App\Models;

use App\Models\Concerns\Restricted;
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
    /**
     * Bounds every quotation query to what the caller may see — list, detail,
     * search and the PDF. Order was scoped and this was not, so a clerk whose
     * own sales were hidden from her colleagues could still read the whole
     * shop's quotations, customer contacts and prices included.
     */
    use Restricted;

    /** The capability that governs reading a quotation. */
    public static function viewPermission(): string
    {
        return 'quotations.view';
    }

    /**
     * created_by is the STAFF member who raised the quote. user_id is the
     * customer's linked account — the same distinction Order draws, and for the
     * same reason: scoping on it would show a clerk the quotes written FOR her
     * rather than the ones written BY her.
     */
    public function ownerColumn(): string
    {
        return 'created_by';
    }

    // Statuses.
    public const DRAFT     = 'draft';
    public const SENT      = 'sent';
    public const ACCEPTED  = 'accepted';
    public const DECLINED  = 'declined';
    public const EXPIRED   = 'expired';
    public const CONVERTED = 'converted';
    /**
     * Withdrawn by the super admin when the unpaid invoice it became was
     * voided (owner, 2026-10-26). Terminal: never offered, accepted or
     * counted again — App\Services\Documents\InvoiceVoid.
     */
    public const VOID      = 'void';

    protected static function booted(): void
    {
        // The reporting rate in force today travels with the quotation (Step 3).
        static::creating(fn (self $q) => \App\Support\ReportingRateStamp::apply($q));
        static::updating(function (self $q) {
            if ($q->isDirty('currency_code')) {
                \App\Support\ReportingRateStamp::apply($q, true);
            }
        });
    }

    protected $fillable = [
        'quote_number',
        'user_id',
        'outlet_id',
        'source',
        'status',
        'currency_code',
        'reporting_rate_to_kes',
        'reporting_rate_source',
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
        'voided_at',
        'voided_by',
        'void_reason',
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
        'voided_at'       => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /** The super admin who voided it (with its invoice). */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable issued sales document — a QUOTATION, INVOICE, or RECEIPT that was
 * actually issued, with its gapless number and a frozen snapshot of what it said
 * at issue time. Never edit an issued row; correct via a new document.
 * See App\Services\DocumentNumberService.
 */
class SalesDocument extends Model
{
    use HasFactory;

    // Types.
    public const QUOTATION = 'quotation';
    public const INVOICE   = 'invoice';
    public const RECEIPT   = 'receipt';

    protected $fillable = [
        'type',
        'number',
        'documentable_type',
        'documentable_id',
        'parent_document_id',
        'payment_id',
        'issued_at',
        'valid_until',
        'due_date',
        'status',
        'amount',
        'currency_code',
        'snapshot',
        'pdf_path',
        'created_by',
    ];

    protected $casts = [
        'issued_at'   => 'datetime',
        'valid_until' => 'date',
        'due_date'    => 'date',
        'amount'      => 'decimal:2',
        'snapshot'    => 'array',
    ];

    /** The Quotation or Order this document was issued for. */
    public function documentable()
    {
        return $this->morphTo();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'parent_document_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}

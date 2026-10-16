<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An issued sales document — a QUOTATION, INVOICE, or RECEIPT that was
 * actually issued, with its gapless number and a frozen snapshot of what it
 * said. Since 2026-10-16 the row holds the CURRENT revision: a revision is
 * made only through App\Services\Documents\SalesDocumentRevisions, which first
 * keeps the version being replaced in document_revisions (never changed,
 * never deleted). Nothing else rewrites an issued row.
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
        'revision',
    ];

    protected $casts = [
        'issued_at'   => 'datetime',
        'valid_until' => 'date',
        'due_date'    => 'date',
        'amount'      => 'decimal:2',
        'snapshot'    => 'array',
        'revision'    => 'integer',
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

    /**
     * The staff member who issued this document — quotation, invoice or
     * receipt alike. All three writers set created_by, so every document in
     * the trail can say who produced it.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

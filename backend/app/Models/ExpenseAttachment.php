<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One piece of evidence on an expense — a photo of the receipt, the paper
 * quotation, the water bill. Append-only (see the create migration):
 * withdrawn, never deleted.
 */
class ExpenseAttachment extends Model
{
    public const KINDS = [
        'receipt'   => 'Receipt',
        'quotation' => 'Quotation',
        'invoice'   => 'Bill / invoice',
        'meter'     => 'Meter reading',
        'other'     => 'Other',
    ];

    /** Most files one expense may carry. */
    public const MAX_PER_EXPENSE = 10;

    protected $fillable = [
        'expense_id', 'kind', 'disk', 'path', 'mime_type', 'size_bytes',
        'original_name', 'uploaded_by', 'removed_at', 'removed_by',
    ];

    protected $casts = [
        'removed_at' => 'datetime',
        'size_bytes' => 'integer',
    ];

    // The storage path is the server's business; the console asks for a link.
    protected $hidden = ['path', 'disk'];

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function remover()
    {
        return $this->belongsTo(User::class, 'removed_by');
    }
}

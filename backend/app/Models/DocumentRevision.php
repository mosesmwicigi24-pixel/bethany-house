<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A document as it was before an owner edit (2026-10-16): the full snapshot,
 * the revision it was, what changed, who and why. Immutable — a record of
 * history is never changed or deleted.
 */
class DocumentRevision extends Model
{
    public const UPDATED_AT = null;

    public const PURCHASE_ORDER = 'purchase_order';
    public const GOODS_RECEIPT  = 'goods_receipt';

    protected $fillable = ['document_type', 'document_id', 'revision', 'snapshot', 'changes', 'reason', 'created_by'];

    protected $casts = [
        'snapshot' => 'array',
        'changes'  => 'array',
        'revision' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('A document revision is a record of history; it never changes.'));
        static::deleting(fn () => throw new \LogicException('A document revision is a record of history; it is not deleted.'));
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The revisions of one document, newest first, as the console lists them. */
    public static function listFor(string $type, int $id): array
    {
        return static::with('author:id,first_name,last_name')
            ->where('document_type', $type)->where('document_id', $id)
            ->orderByDesc('revision')
            ->get()
            ->map(fn (self $r) => [
                'id'         => $r->id,
                'revision'   => $r->revision,
                'reason'     => $r->reason,
                'changes'    => $r->changes ?? [],
                'snapshot'   => $r->snapshot,
                'created_at' => $r->created_at,
                'by'         => $r->author ? trim("{$r->author->first_name} {$r->author->last_name}") : null,
            ])->all();
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Allocates gapless, per-type, sequential document numbers
 * (QUO-2026-0001, INV-2026-0001, RCP-2026-0001).
 *
 * Correctness properties:
 *  - GAPLESS: the counter is incremented in the SAME transaction that issues the
 *    document, so a number is consumed only if the issue commits. (Postgres
 *    SEQUENCEs are not gapless — a rolled-back nextval() still burns the value —
 *    which is why we use a locked counter row instead.)
 *  - CONCURRENCY-SAFE: the counter row is taken with SELECT … FOR UPDATE, so two
 *    concurrent issues serialise and can never receive the same number.
 *  - Sequential gapless invoice numbering is a KRA requirement for tax invoices.
 *
 * MUST be called inside a DB transaction (the caller's — typically the same one
 * that writes the sales_documents row), so the lock is held to commit and the
 * number is not consumed if the surrounding issue fails.
 */
class DocumentNumberService
{
    private const PREFIX = [
        'quotation' => 'QUO',
        'invoice'   => 'INV',
        'receipt'   => 'RCP',
    ];

    /**
     * Allocate and return the next number for a document type.
     *
     * @param  string       $type    quotation | invoice | receipt
     * @param  string|null  $period  numbering period; defaults to the current year
     *                               (numbers reset per year, per common practice)
     */
    public static function next(string $type, ?string $period = null): string
    {
        $prefix = self::PREFIX[$type] ?? null;
        if ($prefix === null) {
            throw new \InvalidArgumentException("Unknown document type: {$type}");
        }

        $period = $period ?? now()->format('Y');

        // Ensure the counter row exists, then lock it. insertOrIgnore is safe under
        // a race (unique on doc_type+period); the loser simply locks the winner's row.
        DB::table('document_number_sequences')->insertOrIgnore([
            'doc_type'    => $type,
            'period'      => $period,
            'last_number' => 0,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $row = DB::table('document_number_sequences')
            ->where('doc_type', $type)
            ->where('period', $period)
            ->lockForUpdate()
            ->first();

        $nextNumber = (int) $row->last_number + 1;

        DB::table('document_number_sequences')
            ->where('id', $row->id)
            ->update(['last_number' => $nextNumber, 'updated_at' => now()]);

        return sprintf('%s-%s-%04d', $prefix, $period, $nextNumber);
    }
}

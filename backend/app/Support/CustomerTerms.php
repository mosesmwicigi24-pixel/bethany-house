<?php

namespace App\Support;

/**
 * The terms printed on every customer receipt, in one place so the PDF and
 * the printed receipts say the same thing. Mirrored by
 * react-admin/src/lib/customerTerms.tsx for the till and order receipts.
 *
 * Repairs and alterations (management notice, 2026-11-19): only for items
 * bought from Bethany House, within 14 days of the customer receiving the
 * item, subject to assessment; none after that, no exceptions.
 */
final class CustomerTerms
{
    public const REPAIR_DAYS = 14;

    /** @return list<string> */
    public static function receipt(): array
    {
        return [
            'Goods once sold are not exchangeable unless within 7 days with the original receipt.',
            self::repairs(),
        ];
    }

    /** The repair rule on its own, as quotations and invoices print it (owner, 2026-11-19). */
    public static function repairs(): string
    {
        return 'Repairs and alterations: only for items bought from Bethany House, within ' . self::REPAIR_DAYS
            . ' days of receiving the item, subject to assessment. None are accepted after ' . self::REPAIR_DAYS . ' days.';
    }

    /** The repair rule as a block under a quotation's or invoice's own terms. */
    public static function repairsHtml(): string
    {
        return '<div class="notes-label" style="margin-top:8px">Standard terms</div><div class="notes-box">'
            . htmlspecialchars(self::repairs()) . '</div>';
    }
}

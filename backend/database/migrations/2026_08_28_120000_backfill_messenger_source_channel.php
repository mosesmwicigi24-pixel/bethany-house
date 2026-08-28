<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Historical chat orders: record the app the customer ACTUALLY used.
 *
 * Neema hardcoded "channel": "whatsapp" on every pending-order push and never
 * sent source_channel, which the hub has validated and stored
 * (whatsapp|messenger|instagram) all along. So every conversational order —
 * Messenger included — landed labelled WhatsApp, and the order page offered a
 * WhatsApp button for customers who have never used WhatsApp with us.
 *
 * The push now carries the truth (see hub_client.push_pending_order), but the
 * orders already on file still read 'whatsapp'. These 49 are the ones Neema's
 * own order_events record as channel='messenger' — one unambiguous source per
 * order (no order was ever pushed under two channels), cross-checked against
 * the hub: all 49 exist, all 49 are sales_bucket='chat'.
 *
 * Listed by ORDER NUMBER rather than id so the change is legible in review.
 *
 * SAFETY: source_channel drives NO financial figure. Every sales report buckets
 * on sales_bucket (Order::scopeSalesChannel); source_channel is descriptive
 * only. This migration cannot move a number in any report — verified by
 * snapshotting the four bucket totals before and after.
 *
 * The WHERE clause pins source_channel='whatsapp', so this is idempotent and
 * will never overwrite a later, better correction.
 *
 * NOT touched: 2 hub chat orders have no Neema record at all, so their true app
 * is unknown. Leaving them is honest; guessing would not be.
 */
return new class extends Migration
{
    private const MESSENGER_ORDERS = [
        'WA-260716-02IDP',
        'WA-260716-GTGUI',
        'WA-260717-VBCOX',
        'WA-260717-XT5ON',
        'WA-260718-TOPC1',
        'WA-260719-QHVRQ',
        'WA-260722-WJUCY',
        'WA-260723-WSPZ6',
        'WA-260728-1G8V6',
        'WA-260728-1RAXZ',
        'WA-260728-C5QGU',
        'WA-260728-QD0NY',
        'WA-260803-W9QJT',
        'WA-260804-C1GG8',
        'WA-260808-FCPYT',
        'WA-260808-PLG7F',
        'WA-260809-CHIO1',
        'WA-260809-UGXNL',
        'WA-260809-V1YST',
        'WA-260810-IX29S',
        'WA-260811-CSGHL',
        'WA-260811-DE8VA',
        'WA-260811-DHMYR',
        'WA-260811-NMLDC',
        'WA-260811-NOQCE',
        'WA-260812-6T65A',
        'WA-260812-ED9AV',
        'WA-260812-KYMSO',
        'WA-260812-QWRWZ',
        'WA-260812-U4TMU',
        'WA-260812-WBD5Z',
        'WA-260812-XHTLT',
        'WA-260813-C8NWP',
        'WA-260813-JPM2K',
        'WA-260813-MDC16',
        'WA-260813-PHSQS',
        'WA-260813-XFPIR',
        'WA-260814-6FGF2',
        'WA-260814-FYQG7',
        'WA-260814-OOXKP',
        'WA-260814-Q9NE6',
        'WA-260814-WLQ5S',
        'WA-260815-2XHN5',
        'WA-260815-KD1P2',
        'WA-260816-M9Q4A',
        'WA-260816-ML5IU',
        'WA-260816-SDMV2',
        'WA-260817-VSRMV',
        'WA-260819-ALRXF',
    ];

    public function up(): void
    {
        DB::table('orders')
            ->whereIn('order_number', self::MESSENGER_ORDERS)
            ->where('source_channel', 'whatsapp')
            ->update(['source_channel' => 'messenger', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('orders')
            ->whereIn('order_number', self::MESSENGER_ORDERS)
            ->where('source_channel', 'messenger')
            ->update(['source_channel' => 'whatsapp', 'updated_at' => now()]);
    }
};

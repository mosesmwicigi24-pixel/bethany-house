<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * orders:phone-coverage — how many till sales carry a phone number.
 *
 * Read-only, counts only (2026-11-24): the chat agent matched its contacts
 * to hub customers by phone and found almost none, so the question is
 * whether walk-in sales are recorded without a number. Prints no name,
 * number or amount — the repository's workflow logs are public.
 *
 * A till sale "has a phone" when the number typed on the sale, or the phone
 * of the customer record attached to it, is a real number (normalize_phone
 * — the one rule; free text and too-short entries are not numbers).
 */
class OrdersPhoneCoverage extends Command
{
    protected $signature = 'orders:phone-coverage {--days=30 : How many days back, in Nairobi time}';

    protected $description = 'Counts of till sales with and without a phone number (read-only, counts only).';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        // Timestamps are stored in Nairobi wall-clock time (app.timezone).
        $from   = now()->subDays($days)->startOfDay();
        $bucket = Order::salesBucketSql('orders');
        $dead   = "'" . implode("','", Order::DEAD_STATUSES) . "'";

        $row = DB::selectOne("
            SELECT
                COUNT(*)                                                             AS sales,
                COUNT(*) FILTER (WHERE orders.customer_id IS NOT NULL)               AS with_customer_record,
                COUNT(*) FILTER (WHERE normalize_phone(orders.customer_phone) IS NOT NULL) AS phone_on_sale,
                COUNT(*) FILTER (WHERE normalize_phone(orders.customer_phone) IS NOT NULL
                                    OR normalize_phone(c.phone) IS NOT NULL)         AS with_phone,
                COUNT(*) FILTER (WHERE normalize_phone(orders.customer_phone) IS NOT NULL
                                   AND orders.customer_id IS NULL)                   AS phone_but_no_customer_record
            FROM orders
            LEFT JOIN customers c ON c.id = orders.customer_id
            WHERE {$bucket} = 'till'
              AND orders.status NOT IN ({$dead})
              AND orders.created_at >= ?
        ", [$from->toDateTimeString()]);

        $sales = (int) $row->sales;
        $with  = (int) $row->with_phone;

        $this->line("Till sales, last {$days} days: {$sales}");
        $this->line("With a phone number: {$with}");
        $this->line('Without a phone number: ' . ($sales - $with));
        $this->line('Phone typed on the sale itself: ' . (int) $row->phone_on_sale);
        $this->line('Attached to a customer record: ' . (int) $row->with_customer_record);
        $this->line('Phone typed but no customer record: ' . (int) $row->phone_but_no_customer_record);

        return self::SUCCESS;
    }
}

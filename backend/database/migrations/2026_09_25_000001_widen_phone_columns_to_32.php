<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phone columns hold 20 characters; the forms that fill them accept 30.
 *
 * A cashier typed "Cooperative Bank of Kenya" into the phone field of a POS
 * order on 2026-09-25. Twenty-five characters: validation passed, the INSERT
 * failed, and the whole order died with "POS pending order failed". She retried
 * eight times in three minutes before shortening it to "Coop Bank of Kenya" and
 * getting through. A typo should be a message, not a crash.
 *
 * Widening rather than tightening, because 20 is genuinely too narrow for what
 * people type: "0722 000 000 / 0733 111 111" is two real numbers and 27
 * characters. 32 matches the phone columns this system already has elsewhere
 * (channel_touchpoints, replenishment_pings, win_back_outreach), so the widths
 * agree with each other afterwards.
 *
 * Every validation rule that writes these columns caps at 30 or 32, so after
 * this nothing that passes validation can overflow the column. The storefront's
 * 40-character rules write leads.phone (255) and interest_carts.phone (40),
 * which are untouched and already wide enough.
 *
 * Postgres widens a varchar in place: a catalogue update, no table rewrite and
 * no scan, so this is instant on every table here regardless of size.
 */
return new class extends Migration
{
    /** table => column. payments_dedup_backup_20260718 is a frozen snapshot, deliberately left alone. */
    private const COLUMNS = [
        'customers'      => 'phone',
        'users'          => 'phone',
        'suppliers'      => 'phone',
        'outlets'        => 'phone',
        'addresses'      => 'phone',
        'user_addresses' => 'phone',
        'orders'         => 'customer_phone',
        'quotations'     => 'customer_phone',
        'payments'       => 'phone_number',
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE varchar(32)");
        }
    }

    /**
     * Narrowing is only safe while nothing has used the new room. A value longer
     * than 20 makes Postgres refuse, which is the correct answer: it says the
     * data no longer fits rather than silently cutting a customer's number in
     * half. Shorten the offending rows first if a rollback is really wanted.
     */
    public function down(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE varchar(20)");
        }
    }
};

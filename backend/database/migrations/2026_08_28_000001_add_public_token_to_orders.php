<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * orders.public_token — the durable, customer-facing identity of an order.
 *
 * Until now no URL in this system meant "this order". There was a PAY SESSION
 * (payment_token, 72 hours, refused once the order is paid), a chat redirect,
 * and a staff route keyed by an internal integer — so the link a customer was
 * given always died. Every payment link Neema ever sent (88 of them) was
 * expired by the time anyone checked; the newest answered "Payment link not
 * found or has expired."
 *
 * public_token separates the two capabilities that were crammed into
 * payment_token:
 *
 *   public_token   VIEW this order, forever          -> /order/{public_token}
 *   payment_token  PAY this order, for 72 hours      -> minted on demand by
 *                                                       the public page
 *
 * So a receipt keeps working for years while the anti-abuse window on actually
 * moving money is unchanged. 192 bits of entropy: the token IS the
 * authorisation, so it must be unguessable — order ids and order numbers must
 * never yield customer data (see PublicOrderController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->unique()->after('payment_token_expires_at');
            $table->timestamp('public_token_issued_at')->nullable()->after('public_token');
        });

        // Backfill every existing order — a receipt link must work for orders
        // placed before this migration, not only for new ones. chunkById keeps
        // memory bounded on a table that will keep growing.
        DB::table('orders')->select('id')->whereNull('public_token')
            ->orderBy('id')->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('orders')->where('id', $row->id)->update([
                        'public_token'           => bin2hex(random_bytes(24)),
                        'public_token_issued_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['public_token', 'public_token_issued_at']);
        });
    }
};

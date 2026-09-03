<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The interest ledger — the Hub half of the storefront's HUB_CONTRACT §7.
 *
 * The storefront has been mirroring every Neema cart here since launch
 * (POST /storefront/interest-carts, keyed on a short cross-channel token
 * "BH-XXXX"), silently no-opping because the endpoint did not exist. The
 * WhatsApp handoff message hands the customer that token — "cart BH-0QVP2358"
 * — and until this table, nothing in the Hub could resolve it.
 *
 * PIPELINE TRUTH ONLY (CLAUDE.md §2): a cart is an expression of interest.
 * Rows here are never revenue, cash, or receivables, and never feed the
 * recognised-sales reconciliation. `subtotal` is a snapshot of what the
 * customer saw in their cart, not a price the business owes or is owed.
 *
 * Rows are durable and never hard-deleted — "abandoned" is a status, not a
 * delete; the ledger is the customer's interest history across channels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interest_carts', function (Blueprint $table) {
            $table->id();

            // The cross-channel handle carried in the WhatsApp handoff.
            // POST upserts on this; one row per cart (tokens rotate per order).
            $table->string('token', 40)->unique();

            // Origin channel, and where the cart was last touched.
            $table->string('channel', 20);
            $table->string('last_channel', 20)->nullable();

            // active_cart → checkout_started → online_order | whatsapp_order | abandoned
            $table->string('status', 30)->default('active_cart')->index();

            // Identity ladder, weakest → strongest (§7 field notes):
            // token = one cart · visitor_id = one browser · session_id = one chat
            // · phone = the person across channels.
            $table->string('visitor_id', 80)->nullable()->index();
            $table->string('session_id', 120)->nullable();
            $table->string('phone', 40)->nullable();
            // Phone::canonical() of `phone` — the join key across formats
            // (0722…, +254…, 254…). Matching happens on this, never on raw.
            $table->string('phone_canonical', 32)->nullable()->index();
            $table->string('name')->nullable();
            $table->string('church', 160)->nullable();
            $table->string('messenger_psid', 80)->nullable();
            $table->string('instagram_psid', 80)->nullable();

            // The cart itself: [{ slug, quantity, measurements?, size? }].
            $table->json('items');
            $table->decimal('subtotal', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();

            // Set when the cart converts (order number of the placed order).
            $table->string('order_ref', 60)->nullable();
            $table->string('source_path', 500)->nullable();

            // Last storefront request id seen — audit only, not an idempotency
            // key: upsert-on-token already makes replays harmless.
            $table->string('client_request_id', 100)->nullable();

            $table->timestamp('converted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interest_carts');
    }
};

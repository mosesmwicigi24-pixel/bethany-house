<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multichannel identity store (Neema epic — foundation).
 *
 * Today a shopper is known only by the single `customers.phone`/`customers.email`
 * columns, which works while Neema only reaches people over WhatsApp (matched by
 * phone). Meta owns three messaging channels — WhatsApp, Instagram DM and
 * Messenger — and the SAME person carries a DIFFERENT, channel-scoped identifier
 * on each:
 *
 *   whatsapp   → wa_id (the phone number's digits)
 *   instagram  → IGSID (Instagram-scoped id) + @username
 *   messenger  → PSID (page-scoped id)
 *
 * A phone column cannot hold an IGSID or a PSID, so an Instagram DM and a later
 * WhatsApp chat from one human look like two strangers. This table lets a single
 * customer own many channel identities, so Neema can resolve any inbound Meta
 * contact back to one customer record.
 *
 * `provider` + `provider_uid` is the natural key for a contact on a channel and
 * is unique — resolving the same contact twice returns the same row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // Channel this identity lives on (whatsapp, instagram, messenger, ...).
            $table->string('provider', 32);
            // Stable, channel-scoped id for the contact (wa_id / IGSID / PSID).
            $table->string('provider_uid', 191);

            // Human-facing handles captured from the platform profile.
            $table->string('username')->nullable();      // e.g. Instagram @handle
            $table->string('display_name')->nullable();  // platform profile name
            // Normalised E.164 phone when the channel exposes one (WhatsApp does).
            // Lets us merge a new channel identity onto an existing phone customer.
            $table->string('phone_e164', 20)->nullable();

            // Raw platform profile snapshot / free-form metadata.
            $table->json('profile')->nullable();

            // Meta hands us platform-authenticated ids, so an identity minted from
            // a genuine inbound webhook is considered verified. Manual links are not.
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_interaction_at')->nullable();

            $table->timestamps();

            // One row per contact per channel.
            $table->unique(['provider', 'provider_uid']);
            $table->index('customer_id');
            $table->index('phone_e164');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_identities');
    }
};

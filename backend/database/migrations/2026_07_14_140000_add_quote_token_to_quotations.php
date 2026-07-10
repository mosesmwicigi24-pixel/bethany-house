<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A public, unguessable token so a quotation can be sent to a customer as a link
 * (/quote/{token}) they can view and accept without logging in — mirrors
 * orders.payment_token / the /pay/{token} pay-link. Minted when the quotation is
 * issued (see QuotationService::issue).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            if (!Schema::hasColumn('quotations', 'quote_token')) {
                $table->string('quote_token', 64)->nullable()->unique();
            }
            if (!Schema::hasColumn('quotations', 'quote_token_expires_at')) {
                $table->timestamp('quote_token_expires_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            foreach (['quote_token', 'quote_token_expires_at'] as $col) {
                if (Schema::hasColumn('quotations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quotation additions: a shipping charge line and the name of the staff member
 * who served the customer (shown on the quote), so a quotation can carry
 * delivery cost and attribution the same way a real order/receipt does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            if (!Schema::hasColumn('quotations', 'shipping_amount')) {
                $table->decimal('shipping_amount', 12, 2)->default(0)->after('tax_amount');
            }
            if (!Schema::hasColumn('quotations', 'served_by')) {
                $table->string('served_by', 150)->nullable()->after('customer_last_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            foreach (['shipping_amount', 'served_by'] as $col) {
                if (Schema::hasColumn('quotations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

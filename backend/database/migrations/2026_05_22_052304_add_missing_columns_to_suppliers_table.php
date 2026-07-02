<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('company_code', 50)->nullable()->unique()->after('id');
            $table->string('type', 50)->nullable()->after('name'); // manufacturer, wholesaler, etc.
            $table->string('status', 20)->default('active')->after('is_active');
            $table->string('supply_category', 50)->nullable()->after('notes');
            $table->string('address_line_1', 255)->nullable()->after('city');
            $table->string('address_line_2', 255)->nullable()->after('address_line_1');
            $table->string('alternate_phone', 20)->nullable()->after('phone');
            $table->string('website', 255)->nullable()->after('alternate_phone');
            $table->string('state', 100)->nullable()->after('city');
            $table->string('tax_number', 50)->nullable()->after('postal_code');
            $table->string('registration_number', 50)->nullable()->after('tax_number');
            $table->decimal('credit_limit', 12, 2)->nullable()->after('payment_terms');
            $table->string('currency', 10)->nullable()->after('credit_limit');
            $table->string('bank_name', 255)->nullable()->after('currency');
            $table->string('bank_account_number', 100)->nullable()->after('bank_name');
            $table->string('bank_account_name', 255)->nullable()->after('bank_account_number');
            $table->string('bank_swift_code', 50)->nullable()->after('bank_account_name');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn([
                'company_code',
                'type',
                'status',
                'supply_category',
                'address_line_1',
                'address_line_2',
                'alternate_phone',
                'website',
                'state',
                'tax_number',
                'registration_number',
                'credit_limit',
                'currency',
                'bank_name',
                'bank_account_number',
                'bank_account_name',
                'bank_swift_code',
            ]);
        });
    }
};

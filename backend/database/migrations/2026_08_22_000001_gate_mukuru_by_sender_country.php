<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mukuru only takes senders in specific markets — a customer in Ethiopia or
 * the USA cannot walk into a Mukuru agent, so showing them "Mukuru App" on
 * the payment page stalls the sale. `configuration.sender_countries` lists
 * the E.164 dialing prefixes of Mukuru's documented SEND markets (mukuru.com
 * site selector + UK site, checked 2026-08-22):
 *
 *   +27 South Africa   +44 United Kingdom   +263 Zimbabwe   +260 Zambia
 *   +265 Malawi        +267 Botswana        +266 Lesotho    +256 Uganda
 *   +250 Rwanda        +254 Kenya
 *
 * Mukuru OPERATES more widely (Mozambique, Eswatini, DRC, Nigeria, Ghana,
 * Tanzania…) but sender capability there is not clearly documented; add a
 * prefix here (or via the DB) when it is verified, and the page follows.
 * PublicPaymentController::methodWorksForCustomer() reads this; methods
 * without the key stay ungated.
 */
return new class extends Migration
{
    private const MUKURU_SENDER_PREFIXES = [
        '+27', '+44', '+263', '+260', '+265', '+267', '+266', '+256', '+250', '+254',
    ];

    public function up(): void
    {
        $row = DB::table('payment_methods')->where('code', 'mukuru')->first();
        if (!$row) {
            return; // environment never seeded the manual methods — nothing to gate
        }

        $config = json_decode($row->configuration ?? '{}', true) ?: [];
        $config['sender_countries'] = self::MUKURU_SENDER_PREFIXES;

        DB::table('payment_methods')->where('code', 'mukuru')->update([
            'configuration' => json_encode($config),
            'updated_at'    => now(),
        ]);
    }

    public function down(): void
    {
        $row = DB::table('payment_methods')->where('code', 'mukuru')->first();
        if (!$row) {
            return;
        }
        $config = json_decode($row->configuration ?? '{}', true) ?: [];
        unset($config['sender_countries']);
        DB::table('payment_methods')->where('code', 'mukuru')->update([
            'configuration' => json_encode($config),
            'updated_at'    => now(),
        ]);
    }
};

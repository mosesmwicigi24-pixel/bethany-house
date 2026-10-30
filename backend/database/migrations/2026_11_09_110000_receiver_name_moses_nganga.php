<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The receiver of Mukuru and Western Union / MoneyGram payments on
 * +254 700 706 875 is registered as Moses Nganga (owner, 2026-10-30). A
 * remittance to that number must name its registered holder, so the name
 * on the pay page (recipient_name and the steps that repeat it) follows.
 * Only these two rails; the domestic M-Pesa rail and the bank transfer
 * (account name Bethany House) are untouched.
 */
return new class extends Migration
{
    private const RAILS = ['mukuru', 'wu_moneygram'];

    public function up(): void
    {
        $this->rename('Moses Mwicigi', 'Moses Nganga');
    }

    public function down(): void
    {
        $this->rename('Moses Nganga', 'Moses Mwicigi');
    }

    private function rename(string $from, string $to): void
    {
        foreach (self::RAILS as $code) {
            $row = DB::table('payment_methods')->where('code', $code)->first();
            if (!$row) {
                continue;
            }
            $config = json_decode($row->configuration ?? '{}', true) ?: [];
            if (!isset($config['instructions'])) {
                continue;
            }
            $config['instructions']['recipient_name'] = $to;
            $config['instructions']['steps'] = array_map(fn ($s) => str_replace($from, $to, (string) $s), $config['instructions']['steps'] ?? []);
            DB::table('payment_methods')->where('code', $code)->update(['configuration' => json_encode($config), 'updated_at' => now()]);
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Paying from abroad in the customer's own currency (owner, 2026-10-30).
 *
 * A South African order in rand opened its payment link to "No payment
 * methods available": Mukuru and Western Union / MoneyGram took only KES and
 * USD, and no bank transfer was set up. Customers abroad are now billed in
 * USD, ZAR, ZMW or GBP (SellableCurrency::forCustomer and their own choice),
 * so every remote rail takes all of them:
 *
 *   mukuru         send to +254 700 706 875 — to the person, or straight to
 *                  that M-Pesa number from the Mukuru app ("send to M-Pesa")
 *   wu_moneygram   the same receiver number
 *   bank_transfer  I&M Bank Kenya, Kenyatta Avenue Branch (I&M Towers),
 *                  account 00106090796350, bank code 57, branch 001,
 *                  SWIFT IMBLKENA
 *
 * Proof-upload rails: the customer sends, uploads the confirmation, staff
 * verify (requires_approval). The sender-country gates set on 2026-08-22
 * (Mukuru only from its send markets; Western Union not offered to Kenyan
 * numbers) are kept: only the currencies and the pay-to details change.
 * The domestic M-Pesa "Send Money" rail is untouched.
 */
return new class extends Migration
{
    private const CURRENCIES = ['KES', 'USD', 'ZAR', 'ZMW', 'GBP'];
    private const PHONE      = '+254700706875';

    public function up(): void
    {
        $now = now();

        $this->update('mukuru', self::CURRENCIES, [
            'recipient_name'  => 'Moses Mwicigi',
            'recipient_phone' => self::PHONE,
            'steps' => [
                'Open the Mukuru app or visit a Mukuru agent.',
                'Send the amount above to Moses Mwicigi, +254 700 706 875 (Kenya) — or choose "Send to M-Pesa" in the app and send to that same number.',
                'Use your order number as the reference.',
                'Upload your Mukuru confirmation below so our team can verify it.',
            ],
        ]);

        $this->update('wu_moneygram', self::CURRENCIES, [
            'recipient_name'  => 'Moses Mwicigi',
            'recipient_phone' => self::PHONE,
            'steps' => [
                'At a Western Union or MoneyGram agent or app, send to Moses Mwicigi, Nairobi, Kenya.',
                'Receiver phone: +254 700 706 875.',
                'Note the MTCN / reference number from your receipt.',
                'Upload your receipt below (include the MTCN) so our team can verify it.',
            ],
            'note' => 'They may ask for the receiver city and country: Nairobi, Kenya.',
        ]);

        $bank = [
            'instructions' => [
                'recipient_name' => null,     // shown as the business name
                'steps' => [
                    'Bank: I&M Bank Kenya — Kenyatta Avenue Branch (I&M Towers).',
                    'Account number: 00106090796350.',
                    'Bank code: 57 · Branch code: 001 · SWIFT: IMBLKENA.',
                    'Use your order number as the payment reference.',
                    'Upload your bank confirmation below so our team can verify it.',
                ],
                'note' => 'From outside Kenya, send by international (SWIFT) transfer to the details above.',
            ],
            'seeded_by' => '2026_11_09_100000',
        ];
        $existing = DB::table('payment_methods')->where('code', 'bank_transfer')->first();
        $config   = array_merge($existing ? (json_decode($existing->configuration ?? '{}', true) ?: []) : [], $bank);
        DB::table('payment_methods')->updateOrInsert(['code' => 'bank_transfer'], [
            'name'                 => 'Bank Transfer',
            'description'          => 'Transfer to our I&M Bank account, then upload the confirmation',
            'type'                 => 'bank_transfer',
            'provider'             => null,
            'is_active'            => true,
            'requires_approval'    => true,
            'supported_currencies' => json_encode(self::CURRENCIES),
            'configuration'        => json_encode($config),
            'sort_order'           => 53,
            'display_order'        => 53,
            'updated_at'           => $now,
            'created_at'           => $existing->created_at ?? $now,
        ]);
    }

    public function down(): void
    {
        $was = ['KES', 'USD'];
        foreach (['mukuru', 'wu_moneygram'] as $code) {
            $row = DB::table('payment_methods')->where('code', $code)->first();
            if (!$row) continue;
            $config = json_decode($row->configuration ?? '{}', true) ?: [];
            if (isset($config['instructions']['recipient_phone'])) {
                $config['instructions']['recipient_phone'] = '+254727891989';
                $config['instructions']['steps'] = array_map(fn ($s) => str_replace(['+254 700 706 875', '+254700706875'], '+254727891989', $s), $config['instructions']['steps'] ?? []);
            }
            DB::table('payment_methods')->where('code', $code)->update(['supported_currencies' => json_encode($was), 'configuration' => json_encode($config), 'updated_at' => now()]);
        }
        DB::table('payment_methods')->where('code', 'bank_transfer')->whereRaw("configuration::jsonb->>'seeded_by' = ?", ['2026_11_09_100000'])->update(['is_active' => false, 'updated_at' => now()]);
    }

    /** New currencies and pay-to details; every other configuration key (the sender-country gates) kept. */
    private function update(string $code, array $currencies, array $instructions): void
    {
        $row = DB::table('payment_methods')->where('code', $code)->first();
        if (!$row) {
            return;   // an environment that never seeded the manual rails
        }
        $config = json_decode($row->configuration ?? '{}', true) ?: [];
        $config['instructions'] = $instructions;
        DB::table('payment_methods')->where('code', $code)->update([
            'supported_currencies' => json_encode($currencies),
            'configuration'        => json_encode($config),
            'is_active'            => true,
            'updated_at'           => now(),
        ]);
    }
};

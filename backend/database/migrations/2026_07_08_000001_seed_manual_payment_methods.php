<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed customer-facing MANUAL payment methods for the /pay/{token} page.
 *
 * These reuse the existing "record intent → upload proof → staff verifies" flow
 * (type = 'manual', shown to customers, no gateway call). Pay-to details live in
 * `configuration.instructions` and are editable later from the DB / admin UI —
 * confirm the recipient details (esp. Western Union city/country) with the owner.
 */
return new class extends Migration
{
    private function methods(): array
    {
        $now = now();
        return [
            [
                'code' => 'mukuru',
                'name' => 'Mukuru App',
                'description' => 'Send via the Mukuru app or agent, then upload proof',
                'supported_currencies' => ['KES', 'USD'],
                'sort_order' => 50,
                'instructions' => [
                    'recipient_name'  => 'Moses Mwicigi',
                    'recipient_phone' => '+254727891989',
                    'steps' => [
                        'Open the Mukuru app or visit a Mukuru agent.',
                        'Send the amount above to Moses Mwicigi, +254727891989 (Kenya).',
                        'Use your order number as the reference.',
                        'Upload your Mukuru confirmation below so our team can verify it.',
                    ],
                ],
            ],
            [
                'code' => 'wu_moneygram',
                'name' => 'Western Union / MoneyGram',
                'description' => 'Send via Western Union or MoneyGram, then upload the receipt',
                'supported_currencies' => ['KES', 'USD'],
                'sort_order' => 51,
                'instructions' => [
                    'recipient_name'  => 'Moses Mwicigi',
                    'recipient_phone' => '+254727891989',
                    'steps' => [
                        'At a Western Union or MoneyGram agent or app, send to Moses Mwicigi, Nairobi, Kenya.',
                        'Receiver phone: +254727891989.',
                        'Note the MTCN / reference number from your receipt.',
                        'Upload your receipt below (include the MTCN) so our team can verify it.',
                    ],
                    'note' => 'They may ask for the receiver city and country: Nairobi, Kenya.',
                ],
            ],
            [
                'code' => 'mpesa_manual',
                'name' => 'M-Pesa (Send Money)',
                'description' => 'Send money on M-Pesa, then upload the confirmation',
                'supported_currencies' => ['KES'],
                'sort_order' => 52,
                'instructions' => [
                    'recipient_name'  => 'Moses Mwicigi',
                    'recipient_phone' => '+254727891989',
                    'steps' => [
                        'Go to M-Pesa → Send Money.',
                        'Send the amount above to +254727891989 (Moses Mwicigi).',
                        'Use your order number as the reference where possible.',
                        'Upload the M-Pesa confirmation SMS or screenshot below.',
                    ],
                ],
            ],
        ];
    }

    public function up(): void
    {
        $now = now();
        foreach ($this->methods() as $m) {
            DB::table('payment_methods')->updateOrInsert(
                ['code' => $m['code']],
                [
                    'name'                 => $m['name'],
                    'description'          => $m['description'],
                    'type'                 => 'manual',
                    'provider'             => null,
                    'is_active'            => true,
                    'requires_approval'    => true,
                    'supported_currencies' => json_encode($m['supported_currencies']),
                    'configuration'        => json_encode(['instructions' => $m['instructions']]),
                    'sort_order'           => $m['sort_order'],
                    'display_order'        => $m['sort_order'],
                    'updated_at'           => $now,
                    'created_at'           => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('payment_methods')
            ->whereIn('code', ['mukuru', 'wu_moneygram', 'mpesa_manual'])
            ->delete();
    }
};

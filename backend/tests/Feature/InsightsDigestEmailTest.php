<?php

namespace Tests\Feature;

use App\Mail\InsightsDigestMail;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The 07:30 morning brief in the executive design (owner, 2026-11-05).
 * The findings and figures are MetricEngine's; these tests read the email:
 * each finding links to its hub page, urgent reads as urgent, and the plain
 * text the brief always had is still there word for word.
 */
class InsightsDigestEmailTest extends TestCase
{
    use RefreshDatabase;

    /** Shaped like the owner's 5 Nov brief. */
    private function sample(): InsightsDigestMail
    {
        $items = [
            ['severity' => 'high', 'title' => 'KES 415,992 owed for over 30 days', 'detail' => '66 orders with balances older than a month.',
             'link' => '/pos/outstanding-balances', 'actions' => [['label' => 'Chase balances']]],
            ['severity' => 'high', 'title' => '37 production orders overdue', 'detail' => 'Worst is 121 days past due.',
             'link' => '/production/orders?status=overdue', 'actions' => [['label' => 'View overdue orders']]],
            ['severity' => 'high', 'title' => 'KES 1,272,784 quoted but never ordered (13 quotes 7+ days old)',
             'detail' => 'Biggest is QUO-2026-0021 — KES 184,474 sitting 42 days without an order.', 'link' => '/reports/sales?tab=collections'],
            ['severity' => 'medium', 'title' => '11 orders were paid more than their totals (KES 63,350)',
             'detail' => 'Collected includes this money; the orders do not account for it. Refund it, hold it as credit, or correct the order — largest: BH-59FRJEQI (KES 21,800 over).',
             'link' => '/sales/orders'],
            ['severity' => 'medium', 'title' => 'Red Garbadin <b>Polyester</b> Material price up 1121.7% on the last purchase',
             'detail' => 'Paid KES 6,200.00 to DK TEXTILES vs a KES 507.50 90-day average — 5 more materials drifting.', 'link' => '/reports/procurement'],
        ];
        $engine = [
            ['icon' => '💰', 'label' => 'On the table', 'value' => 'KES 2,802,686', 'suffix' => '', 'link' => '/reports/sales?tab=collections'],
            ['icon' => '📉', 'label' => 'Bleeding', 'value' => 'KES 0', 'suffix' => '/day', 'link' => '/reports/inventory?tab=intelligence'],
            ['icon' => '🔄', 'label' => 'At risk', 'value' => 'KES 923,310', 'suffix' => '', 'link' => '/reports/customers'],
            ['icon' => '🛒', 'label' => 'Missed attach', 'value' => 'KES 5,748,516', 'suffix' => '', 'link' => '/reports/sales'],
            ['icon' => '🎯', 'label' => 'Radar due', 'value' => '10', 'suffix' => ' (KES 31,200)', 'link' => '/reports/customers'],
            ['icon' => '⛪', 'label' => 'Advent in 24d', 'value' => 'KES 725,129', 'suffix' => '', 'link' => '/reports/procurement?tab=seasonal'],
        ];

        return new InsightsDigestMail('Morning brief — 5 items need attention (3 urgent)', $items, $engine, "Good morning,\n\n1. [URGENT] …", 'https://hub.example/admin');
    }

    public function test_the_brief_reads_as_a_dashboard_with_each_finding_linked_to_its_page(): void
    {
        $html = $this->sample()->render();
        if ($path = env('BRIEF_PREVIEW_PATH')) file_put_contents($path, $html);   // for a screenshot, never in CI

        $this->assertStringContainsString('5 items need your attention — 3 urgent.', $html);
        $this->assertStringContainsString('>Urgent</div>', $html);
        $this->assertStringContainsString('>KES 2,802,686</div>', $html);          // On the table, as a KPI
        $this->assertSame(3, substr_count($html, '● Urgent'));
        $this->assertSame(2, substr_count($html, '● Watch'));
        $this->assertStringContainsString('href="https://hub.example/admin/pos/outstanding-balances"', $html);
        $this->assertStringContainsString('>Chase balances &rarr;</a>', $html);
        $this->assertStringContainsString('href="https://hub.example/admin/production/orders?status=overdue"', $html);
        $this->assertStringContainsString('>Open in Bethany Hub &rarr;</a>', $html);  // a finding with no action label
        $this->assertStringContainsString('href="https://hub.example/admin/reports/procurement?tab=seasonal"', $html);
        $this->assertStringContainsString('href="https://hub.example/admin/reports"', $html);
        // Names from the data are text, never markup.
        $this->assertStringNotContainsString('<b>Polyester</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;Polyester&lt;/b&gt;', $html);
    }

    public function test_the_command_sends_the_designed_brief_with_the_same_plain_text_beside_it(): void
    {
        Mail::fake();
        DB::table('production_orders')->insert([
            'order_number' => 'PRD-LATE', 'product_id' => Product::factory()->create()->id, 'quantity' => 5,
            'status' => 'in_progress', 'due_date' => now()->subDays(4)->format('Y-m-d'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('system_settings')->upsert(
            ['key' => 'eod_delivery', 'value' => json_encode(['email_enabled' => true, 'email_recipients' => 'owner@sonalux.test, manager@sonalux.test']), 'updated_at' => now()],
            ['key'], ['value', 'updated_at'],
        );

        $this->artisan('insights:digest --force')->assertSuccessful();

        Mail::assertSent(InsightsDigestMail::class, 2);
        Mail::assertSent(InsightsDigestMail::class, function (InsightsDigestMail $m) {
            $html = $m->render();
            $text = view('mail.insights-digest-text', ['plain' => $m->plain])->render();

            return $m->hasTo('owner@sonalux.test')
                && str_starts_with($m->subjectLine, 'Morning brief — ')
                && str_contains($html, '1 production order overdue')
                && str_contains($html, '/production/orders?status=overdue')
                && str_contains($text, "1. [URGENT] 1 production order overdue\n   Worst is 4 days past due.")
                && str_contains($text, 'Every number above is computed from live records');
        });
    }

    public function test_a_test_send_reaches_the_owner_alone_and_leaves_the_real_send_alone(): void
    {
        Mail::fake();
        \Illuminate\Support\Facades\Http::fake();
        config(['audit.owner_email' => 'owner@bethany.test']);
        DB::table('production_orders')->insert([
            'order_number' => 'PRD-LATE', 'product_id' => Product::factory()->create()->id, 'quantity' => 5,
            'status' => 'in_progress', 'due_date' => now()->subDays(4)->format('Y-m-d'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('system_settings')->upsert(
            ['key' => 'eod_delivery', 'value' => json_encode([
                'email_enabled' => true, 'email_recipients' => 'manager@sonalux.test',
                'whatsapp_enabled' => true, 'whatsapp_recipients' => '+254712345678',
            ]), 'updated_at' => now()],
            ['key'], ['value', 'updated_at'],
        );
        DB::table('system_settings')->upsert(['key' => 'insights_digest_last_sent', 'value' => now()->toDateString(), 'updated_at' => now()], ['key'], ['value', 'updated_at']);

        // Even after today's real brief went out, the test goes — to the owner only.
        $this->artisan('insights:digest --owner-only')
            ->expectsOutputToContain('Test brief sent to the owner.')
            ->doesntExpectOutputToContain('manager@sonalux.test')
            ->assertSuccessful();

        Mail::assertSent(InsightsDigestMail::class, 1);
        Mail::assertSent(InsightsDigestMail::class, fn ($m) => $m->hasTo('owner@bethany.test') && !$m->hasTo('manager@sonalux.test'));
        \Illuminate\Support\Facades\Http::assertNothingSent();   // no WhatsApp

        // The guard is untouched: tomorrow's 07:30 send is decided as before.
        DB::table('system_settings')->where('key', 'insights_digest_last_sent')->update(['value' => '2000-01-01']);
        $this->artisan('insights:digest --owner-only')->assertSuccessful();
        $this->assertSame('2000-01-01', DB::table('system_settings')->where('key', 'insights_digest_last_sent')->value('value'));
    }
}

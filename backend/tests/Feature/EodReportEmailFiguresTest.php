<?php

namespace Tests\Feature;

use App\Jobs\SendEodReportEmail;
use App\Jobs\SendEodReportSlack;
use App\Mail\EodReportMail;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The nightly EoD email said every cashier had taken nothing.
 *
 * fetchReports() selects the day's submitted reports, then for each one looks
 * up that cashier's orders with
 *
 *     ->where('ord.outlet_id',  $row->outlet_id ?? 0)
 *     ->where('ord.created_by', $row->user_id   ?? 0)
 *
 * but r.outlet_id and r.user_id were never in the SELECT. Both properties were
 * therefore undefined, the `?? 0` caught that, and the query filtered on an id
 * no row has. Every cashier's order list came back empty and every figure —
 * total sales, paid, balance — printed as zero.
 *
 * The `?? 0` is what made it silent. Without it PHP would have raised on the
 * undefined property the first night this ran.
 *
 * The Slack job does the same work and never had the bug: it selects both
 * columns and filters without a fallback. So the strongest guard is not an
 * absolute figure but the two jobs agreeing, which is the last test here.
 */
class EodReportEmailFiguresTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;
    private User $cashier;
    private string $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->date    = today()->toDateString();
        $this->outlet  = Outlet::factory()->create();
        $this->cashier = User::factory()->create(['first_name' => 'Jackline', 'last_name' => 'Mwicigi']);

        // Her day: two sales worth 4,500, and a colleague's 9,000 that must
        // not land on her line of the report.
        $this->sale($this->cashier, 1500);
        $this->sale($this->cashier, 3000);
        $this->sale(User::factory()->create(), 9000);

        $this->submitEodReport($this->cashier);
    }

    private function sale(User $seller, float $amount): Order
    {
        return Order::factory()->create([
            'created_by'   => $seller->id,
            'outlet_id'    => $this->outlet->id,
            'order_type'   => 'pos',
            'status'       => 'completed',
            'total_amount' => $amount,
        ]);
    }

    private function submitEodReport(User $user): void
    {
        $registerId = DB::table('cash_registers')->insertGetId([
            'register_number' => 'REG-' . $user->id,
            'outlet_id'       => $this->outlet->id,
            'register_name'   => 'Till 1',
            'status'          => 'closed',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        DB::table('cash_register_eod_reports')->insert([
            'register_id'  => $registerId,
            'user_id'      => $user->id,
            'outlet_id'    => $this->outlet->id,
            'report_date'  => $this->date,
            'sentiments'   => 'Busy morning.',
            'order_notes'  => json_encode([]),
            'submitted_at' => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    /** Both jobs keep fetchReports private; the invariant is what it returns. */
    private function fetch(object $job): array
    {
        $m = new \ReflectionMethod($job, 'fetchReports');
        $m->setAccessible(true);

        return $m->invoke($job)->toArray();
    }

    // ── the figure itself ───────────────────────────────────────────────────

    public function test_the_email_reports_what_the_cashier_actually_took(): void
    {
        Mail::fake();

        (new SendEodReportEmail(['owner@bethanyhouse.co.ke'], $this->date, []))->handle();

        Mail::assertSent(EodReportMail::class, function (EodReportMail $mail) {
            $this->assertCount(1, $mail->reports, 'one submitted report');
            $row = $mail->reports[0];

            $this->assertSame('Jackline Mwicigi', $row->user_name);
            $this->assertSame(4500.0, (float) $row->total_sales,
                'her two sales, not zero and not the colleague\'s 9,000');
            $this->assertCount(2, $row->orders);

            return true;
        });
    }

    public function test_a_colleagues_takings_stay_off_her_line(): void
    {
        $reports = $this->fetch(new SendEodReportEmail([], $this->date, []));

        $this->assertSame(4500.0, (float) $reports[0]->total_sales);
        $this->assertNotContains(9000.0,
            collect($reports[0]->orders)->pluck('total_amount')->all());
    }

    /**
     * The regression guard. A missing column must break, not quietly report a
     * day of nothing — which is why the `?? 0` fallbacks are gone.
     */
    public function test_the_email_and_slack_jobs_agree(): void
    {
        $email = $this->fetch(new SendEodReportEmail([], $this->date, []));
        $slack = $this->fetch(new SendEodReportSlack('#eod', $this->date, []));

        $this->assertSame(
            (float) $slack[0]->total_sales,
            (float) $email[0]->total_sales,
            'the two nightly reports must not disagree about the same day'
        );
    }
}

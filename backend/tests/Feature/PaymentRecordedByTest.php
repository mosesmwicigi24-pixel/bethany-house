<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Role hardening 4D: payments.recorded_by — who recorded a payment, on the row
 * itself, so maker≠checker on approval no longer depends on an audit entry
 * that a failed write could have lost (it failed OPEN when the entry was missing).
 */
class PaymentRecordedByTest extends TestCase
{
    use RefreshDatabase;

    private const BACKFILL = '2026_10_03_480003_backfill_payments_recorded_by_from_audit_trail.php';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
    }

    private function user(string $role): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $u->assignRole(Role::findByName($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return $u->fresh();
    }

    private function pending(array $attrs = []): Payment
    {
        $order = Order::factory()->create(['status' => 'processing', 'payment_status' => 'pending_approval', 'total_amount' => 1000]);
        return Payment::create($attrs + [
            'order_id' => $order->id, 'payment_method' => 'bank_transfer', 'amount' => 1000,
            'currency_code' => 'KES', 'status' => 'pending', 'requires_approval' => true,
            'approval_status' => 'pending_review',
        ]);
    }

    public function test_every_payment_creation_site_names_its_recorder(): void
    {
        // The whole class, not the first hit: every Payment::create in the app
        // states recorded_by — the person, or null for the public pay page and
        // the gateway webhooks.
        $missing = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $src = file_get_contents($file->getPathname());
            $offset = 0;
            while (($pos = strpos($src, 'Payment::create(', $offset)) !== false) {
                $end = strpos($src, ']);', $pos);
                $call = substr($src, $pos, ($end === false ? 4000 : $end - $pos));
                $line = substr_count(substr($src, 0, $pos), "\n") + 1;
                if (!str_contains($call, "'recorded_by'")) {
                    $missing[] = str_replace(app_path() . '/', '', $file->getPathname()) . ':' . $line;
                }
                $offset = $pos + 1;
            }
        }
        $this->assertSame([], $missing, "Payment::create without recorded_by:\n" . implode("\n", $missing));
    }

    public function test_an_order_payment_is_stamped_with_whoever_recorded_it(): void
    {
        $clerk = $this->user('super_admin');
        Sanctum::actingAs($clerk);
        $order = Order::factory()->create(['order_type' => 'pos', 'status' => 'processing', 'total_amount' => 500, 'payment_status' => 'pending']);

        $this->postJson("/api/v1/admin/orders/{$order->id}/payments", ['method' => 'cash', 'amount' => 100])->assertSuccessful();

        $this->assertSame($clerk->id, (int) Payment::where('order_id', $order->id)->value('recorded_by'));
    }

    public function test_the_model_fills_in_the_signed_in_user_but_respects_an_explicit_null(): void
    {
        $u = $this->user('finance_manager');
        $this->actingAs($u, 'sanctum');

        $this->assertSame($u->id, (int) $this->pending()->fresh()->recorded_by);
        $this->assertNull($this->pending(['recorded_by' => null])->fresh()->recorded_by);
    }

    public function test_approval_reads_recorded_by_even_when_the_audit_entry_is_missing(): void
    {
        $finance = $this->user('finance_manager');
        // No signed-in user while creating: no audit 'created' row has a causer.
        $payment = $this->pending(['recorded_by' => $finance->id]);
        $this->assertFalse(DB::table('activity_log')->where('subject_type', Payment::class)
            ->where('subject_id', $payment->id)->whereNotNull('causer_id')->exists());

        Sanctum::actingAs($finance);
        $this->postJson("/api/v1/admin/payments/{$payment->id}/approve", ['notes' => 'ok'])
            ->assertForbidden()->assertJsonPath('code', 'SELF_APPROVAL');
        $this->assertSame('pending_review', $payment->fresh()->approval_status);
    }

    public function test_the_backfill_fills_only_nulls_from_the_audit_trail_and_records_its_counts(): void
    {
        // The backfill writes production data and ships separately, only on the
        // owner's explicit YES (it is not on the integration branch). This test
        // runs, unchanged, on the branch that carries the migration file.
        if (!file_exists(database_path('migrations/' . self::BACKFILL))) {
            $this->markTestSkipped(self::BACKFILL . ' ships separately, on the owner\'s explicit YES.');
        }

        $alice = $this->user('pos_clerk');
        $bob   = $this->user('pos_clerk');
        $gone  = $this->user('pos_clerk');

        $fromAudit = $this->pending(['recorded_by' => null]);
        $kept      = $this->pending(['recorded_by' => $bob->id]);
        $noTrail   = $this->pending(['recorded_by' => null]);
        $orphan    = $this->pending(['recorded_by' => null]);

        $audit = fn (Payment $p, int $causer) => DB::table('activity_log')->insert([
            'log_name' => 'model', 'description' => 'Created Payment', 'event' => 'created', 'action' => 'created',
            'subject_type' => Payment::class, 'subject_id' => $p->id,
            'causer_type' => User::class, 'causer_id' => $causer, 'properties' => '{}',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $audit($fromAudit, $alice->id);
        $audit($fromAudit, $bob->id);     // a later 'created' row: the FIRST one wins
        $audit($kept, $alice->id);        // never overwrites a stamped row
        $audit($orphan, $gone->id);
        DB::table('model_has_roles')->where('model_id', $gone->id)->delete();
        DB::table('users')->where('id', $gone->id)->delete();

        (require database_path('migrations/' . self::BACKFILL))->up();

        $this->assertSame($alice->id, (int) $fromAudit->fresh()->recorded_by);
        $this->assertSame($bob->id, (int) $kept->fresh()->recorded_by);
        $this->assertNull($noTrail->fresh()->recorded_by);
        $this->assertNull($orphan->fresh()->recorded_by);

        $log = DB::table('activity_log')->where('event', 'payments_recorded_by_backfilled')->latest('id')->first();
        $this->assertNotNull($log, 'the backfill must record what it did');
        $counts = json_decode($log->properties, true);
        $this->assertSame(1, $counts['filled']);
        $this->assertSame(1, $counts['no_audit_row']);
        $this->assertSame(1, $counts['causer_missing']);
    }
}

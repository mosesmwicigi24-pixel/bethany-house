<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 4A adversarial review (plan §17) — the attacks a curious or hostile
 * insider tries against data scope, masking, export parity and search:
 *
 *   1. an outlet manager of A asks for B by filter (?outlet_id=B and its
 *      variants) → empty, and by id → 404;
 *   2. walking every id returns only A's records;
 *   3. dropping every filter is still scoped;
 *   4. the export's row set is the screen's row set, under every tamper;
 *   5. the JSON each role receives carries no contact it may not see in full;
 *   6. a scripted search burst is stopped (429).
 *
 * Uses the real role catalogue (permission:sync).
 */
class Phase4aAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $a;
    private Outlet $b;

    /** @var Order[] */
    private array $ordersA = [];
    /** @var Order[] */
    private array $ordersB = [];
    private Customer $custA;
    private Customer $custB;

    private const PHONE_A = '0711223344';
    private const EMAIL_A = 'nancy82@gmail.com';
    private const PHONE_B = '0722556677';
    private const EMAIL_B = 'peter.b@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        Storage::fake('local');
        $this->a = Outlet::factory()->create(['name' => 'Outlet A']);
        $this->b = Outlet::factory()->create(['name' => 'Outlet B']);

        $this->custA = Customer::create(['first_name' => 'Nancy', 'last_name' => 'Alpha', 'phone' => self::PHONE_A, 'email' => self::EMAIL_A, 'created_outlet_id' => $this->a->id]);
        $this->custB = Customer::create(['first_name' => 'Peter', 'last_name' => 'Bravo', 'phone' => self::PHONE_B, 'email' => self::EMAIL_B, 'created_outlet_id' => $this->b->id]);

        foreach ([[$this->a, $this->custA, self::PHONE_A, self::EMAIL_A], [$this->b, $this->custB, self::PHONE_B, self::EMAIL_B]] as [$outlet, $c, $phone, $email]) {
            for ($i = 0; $i < 3; $i++) {
                $o = Order::factory()->create([
                    'outlet_id' => $outlet->id, 'customer_id' => $c->id, 'status' => $i === 2 ? 'pending' : 'confirmed',
                    'customer_first_name' => $c->first_name, 'customer_last_name' => $c->last_name,
                    'customer_phone' => $phone, 'customer_email' => $email,
                ]);
                if ($outlet->is($this->a)) {
                    $this->ordersA[] = $o;
                } else {
                    $this->ordersB[] = $o;
                }
            }
        }
    }

    private function as(string $role, ?Outlet $outlet = null): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole(Role::findByName($role, 'sanctum'));
        if ($outlet) {
            $user->outlets()->attach($outlet->id, ['is_primary' => true]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user = $user->fresh());

        return $user;
    }

    private function ids($response, string $key = 'data'): array
    {
        return collect($response->assertOk()->json($key))->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();
    }

    private function idsOf(array $models): array
    {
        return collect($models)->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();
    }

    private function csvNumbers($response): array
    {
        $lines = array_values(array_filter(explode("\n", ltrim((string) $response->getContent(), "\xEF\xBB\xBF"))));
        array_shift($lines);

        return collect($lines)->map(fn ($l) => str_getcsv($l)[0])->sort()->values()->all();
    }

    // ── 1. filter tampering ─────────────────────────────────────────────────

    public function test_asking_for_outlet_b_by_filter_is_empty_and_by_id_is_404(): void
    {
        $this->as('outlet_manager', $this->a);
        $b = $this->b->id;

        foreach (["outlet_id={$b}", "outlet_id[]={$b}", "outlet_ids[]={$b}", "outlet={$b}", "outlet_id={$b}&outlet_id={$this->a->id}"] as $tamper) {
            $got = $this->ids($this->getJson("/api/v1/admin/orders?{$tamper}"));
            $this->assertEmpty(array_intersect($got, $this->idsOf($this->ordersB)), "orders?{$tamper} leaked B");
        }
        $this->assertSame([], $this->ids($this->getJson("/api/v1/admin/orders?outlet_id={$b}")));
        $this->assertSame([], $this->ids($this->getJson("/api/v1/admin/quotations?outlet_id={$b}")));

        $this->getJson("/api/v1/admin/orders/{$this->ordersB[0]->id}")->assertNotFound();
        $this->getJson("/api/v1/admin/customers/{$this->custB->id}")->assertNotFound();
    }

    // ── 2. id enumeration ───────────────────────────────────────────────────

    public function test_walking_every_id_returns_only_outlet_a(): void
    {
        $this->as('outlet_manager', $this->a);
        $maxOrder = Order::withoutGlobalScopes()->max('id');
        $maxCust  = Customer::max('id');

        $seen = [];
        for ($id = 1; $id <= $maxOrder + 2; $id++) {
            $r = $this->getJson("/api/v1/admin/orders/{$id}");
            $this->assertContains($r->status(), [200, 404], "order {$id}: never 403 (no existence oracle) or 500");
            if ($r->status() === 200) {
                $seen[] = $id;
            }
        }
        $this->assertSame($this->idsOf($this->ordersA), $seen);

        $seen = [];
        for ($id = 1; $id <= $maxCust + 2; $id++) {
            $r = $this->getJson("/api/v1/admin/customers/{$id}");
            $this->assertContains($r->status(), [200, 404]);
            if ($r->status() === 200) {
                $seen[] = $id;
            }
        }
        $this->assertSame([$this->custA->id], $seen);
    }

    // ── 3. no filters at all ────────────────────────────────────────────────

    public function test_removing_every_filter_is_still_scoped(): void
    {
        $this->as('outlet_manager', $this->a);

        $this->assertSame($this->idsOf($this->ordersA), $this->ids($this->getJson('/api/v1/admin/orders?per_page=100')));
        $this->assertSame([$this->custA->id], $this->ids($this->getJson('/api/v1/admin/customers?per_page=100')));
        $this->assertStringNotContainsString(self::PHONE_B, $this->getJson('/api/v1/admin/orders/pending-queue')->assertOk()->getContent());
    }

    // ── 4. export parity ────────────────────────────────────────────────────

    public function test_the_export_row_set_equals_the_screen_row_set_under_every_tamper(): void
    {
        $this->as('outlet_manager', $this->a);
        $b = $this->b->id;

        foreach (['', 'status=confirmed', "outlet_id={$b}", "outlet_id[]={$b}", 'search=Peter', 'sort_by=total_amount&sort_order=asc', 'per_page=1'] as $q) {
            $screen = collect($this->getJson('/api/v1/admin/orders?' . $q . ($q === 'per_page=1' ? '' : '&per_page=100'))->assertOk()->json('data'))
                ->pluck('order_number');
            $export = $this->csvNumbers($this->get("/api/v1/admin/orders/export?{$q}")->assertOk());
            if ($q === 'per_page=1') {
                // Paging is the screen's window; the export is the whole filtered set.
                $this->assertSame(collect($this->ordersA)->pluck('order_number')->sort()->values()->all(), $export);
                continue;
            }
            $this->assertSame($screen->sort()->values()->all(), $export, "export?{$q}");
        }
    }

    // ── 5. contacts in the JSON, per role ───────────────────────────────────

    /** Every response a role can open over customers' contacts. */
    private function surfaces(): array
    {
        return [
            '/api/v1/admin/orders?per_page=100',
            "/api/v1/admin/orders/{$this->ordersA[0]->id}",
            '/api/v1/admin/orders/pending-queue',
            '/api/v1/admin/customers?per_page=100',
            "/api/v1/admin/customers/{$this->custA->id}",
            '/api/v1/admin/pos/customers/search?q=Nancy',
            '/api/v1/admin/search?q=Nancy',
        ];
    }

    private function assertNoRawContacts(string $role, ?Outlet $outlet): int
    {
        $user = $this->as($role, $outlet);
        // A cashier's orders are her own: give her one with the customer on it.
        if ($role === 'pos_clerk') {
            Order::withoutGlobalScopes()->whereKey($this->ordersA[0]->id)->update(['created_by' => $user->id]);
        }

        $opened = 0;
        foreach ($this->surfaces() as $url) {
            $r = $this->getJson($url);
            if ($r->status() !== 200) {
                continue;   // a door this role does not have
            }
            $opened++;
            $body = $r->getContent();
            foreach ([self::PHONE_A, self::EMAIL_A, self::PHONE_B, self::EMAIL_B, '711223344', 'nancy82@'] as $raw) {
                $this->assertStringNotContainsString($raw, $body, "{$role} received {$raw} in full from {$url}");
            }
        }

        return $opened;
    }

    public function test_no_masked_role_receives_a_contact_in_full(): void
    {
        $this->assertGreaterThanOrEqual(3, $this->assertNoRawContacts('accountant', null), 'accountant opened real surfaces');
        $this->assertGreaterThanOrEqual(2, $this->assertNoRawContacts('pos_clerk', $this->a), 'cashier opened real surfaces');
        foreach (['procurement_manager', 'procurement_officer', 'tailor', 'system_admin'] as $role) {
            $this->assertNoRawContacts($role, $this->a);
        }
    }

    public function test_the_masked_shape_is_what_a_masked_role_sees_and_the_manager_sees_in_full(): void
    {
        $this->as('accountant');
        $row = collect($this->getJson('/api/v1/admin/orders?per_page=100')->assertOk()->json('data'))->firstWhere('id', $this->ordersA[0]->id);
        $this->assertSame('07••••3344', $row['customer_phone']);
        $this->assertSame('n•••82@gmail.com', $row['customer_email']);

        // Control: the test can see a raw value when one is sent.
        $this->as('outlet_manager', $this->a);
        $this->assertStringContainsString(self::PHONE_A, $this->getJson("/api/v1/admin/orders/{$this->ordersA[0]->id}")->assertOk()->getContent());
    }

    // ── 6. scripted search ──────────────────────────────────────────────────

    public function test_a_scripted_search_burst_gets_429_on_every_door(): void
    {
        $this->as('outlet_manager', $this->a);

        $prefixes = [];
        for ($i = 0; $i < 30; $i++) {
            $prefixes[] = '07' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        }
        foreach ($prefixes as $i => $p) {
            $url = $i % 2 ? "/api/v1/admin/customers?search={$p}" : "/api/v1/admin/pos/customers/search?q={$p}";
            $this->getJson($url)->assertOk();
        }

        $this->getJson('/api/v1/admin/pos/customers/search?q=0730')->assertStatus(429)->assertJsonPath('code', 'customer_search_rate_limit');
        $this->getJson('/api/v1/admin/customers?search=0731')->assertStatus(429);
        $this->getJson('/api/v1/admin/search?q=0732')->assertStatus(429);
    }

    public function test_wildcards_and_short_terms_do_not_widen_a_search(): void
    {
        $this->as('outlet_manager', $this->a);

        foreach (['%', '%%%', '___', '%_%', '07', ' 0 7 '] as $q) {
            $this->getJson('/api/v1/admin/pos/customers/search?q=' . urlencode($q))->assertStatus(422);
            $this->getJson('/api/v1/admin/customers?search=' . urlencode($q))->assertStatus(422);
        }
        // A real term never reaches past scope.
        $this->assertSame([], $this->getJson('/api/v1/admin/pos/customers/search?q=Peter')->assertOk()->json('data'));
        $this->assertSame([], $this->ids($this->getJson('/api/v1/admin/customers?search=Peter')));
    }
}

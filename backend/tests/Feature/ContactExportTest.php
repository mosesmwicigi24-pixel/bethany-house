<?php

namespace Tests\Feature;

use App\Models\DownloadApprover;
use App\Models\DownloadRequest;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use App\Services\Downloads\DownloadPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\StepsUp;
use Tests\TestCase;

/**
 * Phase 4A export parity (plan §9): the orders export runs the screen's own
 * query (same scope, filters and order), is masked by the screen's rule, is
 * capped, and — past 200 rows of unmasked customer contacts — is a
 * bulk-contacts download only a super admin decides. Whether the gate holds is
 * still audit.downloads.enforce (default off: recorded, not held).
 */
class ContactExportTest extends TestCase
{
    use RefreshDatabase, StepsUp;

    private Outlet $a;
    private Outlet $b;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        config(['audit.owner_account_email' => 'owner@bethany.test', 'audit.owner_email' => 'copies@bethany.test']);
        Storage::fake('local');
        Queue::fake();
        Mail::fake();
        Notification::fake();

        $this->a     = Outlet::factory()->create(['name' => 'Outlet A']);
        $this->b     = Outlet::factory()->create(['name' => 'Outlet B']);
        $this->owner = $this->user('super_admin', null, 'owner@bethany.test');
    }

    private function user(string $role, ?Outlet $outlet = null, ?string $email = null): User
    {
        $user = User::factory()->create(array_filter(['status' => 'active', 'email' => $email]));
        $user->assignRole(Role::findByName($role, 'sanctum'));
        if ($outlet) {
            $user->outlets()->attach($outlet->id, ['is_primary' => true]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function actAs(User $user): User
    {
        Sanctum::actingAs($user);
        // The orders export and a download approval are step-up routes (Phase 4C).
        $this->stepUp($user);

        return $user;
    }

    private function orders(Outlet $outlet, int $n, array $extra = []): void
    {
        Order::factory()->count($n)->sequence(fn ($s) => [
            'customer_phone' => '07' . str_pad((string) (11000000 + $s->index), 8, '0', STR_PAD_LEFT),
            'customer_email' => "buyer{$s->index}@example.test",
        ])->create(array_merge(['outlet_id' => $outlet->id, 'status' => 'confirmed'], $extra));
    }

    /** Order numbers in an export CSV, in file order. */
    private function csvNumbers($response): array
    {
        $lines = array_values(array_filter(explode("\n", ltrim((string) $response->getContent(), "\xEF\xBB\xBF"))));
        array_shift($lines);

        return array_map(fn ($l) => str_getcsv($l)[0], $lines);
    }

    private function latestDownload(): DownloadRequest
    {
        return DownloadRequest::latest('id')->firstOrFail();
    }

    public function test_the_export_is_the_screens_rows_in_the_screens_order(): void
    {
        $this->orders($this->a, 6);
        $this->orders($this->a, 3, ['status' => 'pending']);
        $this->orders($this->b, 5);
        $this->actAs($this->user('outlet_manager', $this->a));

        $q = 'status=confirmed&sort_by=order_number&sort_order=asc';
        $screen = collect($this->getJson("/api/v1/admin/orders?{$q}&per_page=100")->assertOk()->json('data'))->pluck('order_number')->all();
        $export = $this->csvNumbers($this->get("/api/v1/admin/orders/export?{$q}")->assertOk());

        $this->assertCount(6, $screen);
        $this->assertSame($screen, $export);
    }

    public function test_a_masked_role_gets_a_masked_csv(): void
    {
        $this->orders($this->a, 2);
        $this->actAs($this->user('accountant'));

        $csv = $this->get('/api/v1/admin/orders/export')->assertOk()->getContent();
        $this->assertStringNotContainsString('0711000000', $csv);
        $this->assertStringNotContainsString('buyer0@example.test', $csv);
        $this->assertStringContainsString('07••••0000', $csv);
    }

    public function test_more_than_200_unmasked_contacts_is_a_bulk_contacts_download_recorded_while_not_enforcing(): void
    {
        config(['audit.downloads.enforce' => false]);     // the shipped default
        $this->orders($this->a, 201);
        $this->actAs($this->user('outlet_manager', $this->a));

        $r = $this->get('/api/v1/admin/orders/export')->assertOk();
        $this->assertCount(201, $this->csvNumbers($r));
        $dr = $this->latestDownload();
        $this->assertSame(DownloadPolicy::BULK_CONTACTS, $dr->category);
        $this->assertTrue($dr->shadow, 'recorded as would-have-been-held, not held');
        $this->assertDatabaseHas('activity_log', ['description' => "Export of 201 customers' contacts — super admin approval"]);
    }

    public function test_200_rows_or_masked_contacts_are_an_ordinary_gated_download(): void
    {
        $this->orders($this->a, 200);
        $this->actAs($this->user('outlet_manager', $this->a));
        $this->get('/api/v1/admin/orders/export')->assertOk();
        $this->assertSame(DownloadPolicy::GATED, $this->latestDownload()->category, 'exactly 200 is not more than 200');

        $this->orders($this->b, 5);
        $this->actAs($this->user('accountant'));    // 205 rows, but masked
        $this->get('/api/v1/admin/orders/export')->assertOk();
        $this->assertSame(DownloadPolicy::GATED, $this->latestDownload()->category);
    }

    public function test_when_enforcing_only_a_super_admin_approver_decides_a_bulk_contacts_export(): void
    {
        config(['audit.downloads.enforce' => true]);
        $this->orders($this->a, 201);
        $manager = $this->actAs($this->user('outlet_manager', $this->a));

        $uuid = $this->getJson('/api/v1/admin/orders/export')->assertStatus(403)
            ->assertJsonPath('code', 'download_approval_required')
            ->assertJsonPath('download.category', DownloadPolicy::BULK_CONTACTS)
            ->json('held');
        $this->postJson('/api/v1/admin/downloads/requests', ['held' => $uuid, 'reason' => 'Parish mailing list'])->assertCreated();

        // A delegated approver who is not a super admin may not decide it.
        $delegate = $this->user('admin');
        DownloadApprover::create(['user_id' => $delegate->id, 'assigned_by' => $this->owner->id]);
        $this->actAs($delegate);
        $this->postJson("/api/v1/admin/downloads/requests/{$uuid}/approve")->assertStatus(403);

        // The owner (super admin) may.
        $this->actAs($this->owner);
        $this->postJson("/api/v1/admin/downloads/requests/{$uuid}/approve")->assertOk();

        $this->actAs($manager);
        $t = $this->postJson("/api/v1/admin/downloads/requests/{$uuid}/token")->assertOk()->json();
        $this->assertCount(201, $this->csvNumbers($this->get('/api/v1/admin/orders/export', [$t['header'] => $t['token']])->assertOk()));
    }

    public function test_a_delegates_approval_does_not_cover_an_export_that_grew_past_200(): void
    {
        config(['audit.downloads.enforce' => true]);
        $this->orders($this->a, 200);
        $manager = $this->actAs($this->user('outlet_manager', $this->a));
        $uuid = $this->getJson('/api/v1/admin/orders/export')->assertStatus(403)->json('held');
        $this->postJson('/api/v1/admin/downloads/requests', ['held' => $uuid, 'reason' => 'Month end'])->assertCreated();

        $delegate = $this->user('admin');
        DownloadApprover::create(['user_id' => $delegate->id, 'assigned_by' => $this->owner->id]);
        $this->actAs($delegate);
        $this->postJson("/api/v1/admin/downloads/requests/{$uuid}/approve")->assertOk();

        $this->orders($this->a, 1);     // now 201
        $this->actAs($manager);
        $t = $this->postJson("/api/v1/admin/downloads/requests/{$uuid}/token")->assertOk()->json();
        $this->get('/api/v1/admin/orders/export', [$t['header'] => $t['token']])
            ->assertStatus(403)->assertJsonPath('code', 'download_bulk_contacts_needs_super_admin');
    }

    public function test_the_row_cap_cuts_and_says_so(): void
    {
        config(['audit.downloads.export_row_cap' => 5]);
        $this->orders($this->a, 7);
        $this->actAs($this->owner);

        $r = $this->get('/api/v1/admin/orders/export')->assertOk();
        $this->assertCount(5, $this->csvNumbers($r));
        $this->assertSame('1', $r->headers->get('X-Export-Truncated'));
        $this->assertSame('5', $r->headers->get('X-Export-Row-Cap'));

        config(['audit.downloads.export_row_cap' => 10]);
        $r = $this->get('/api/v1/admin/orders/export')->assertOk();
        $this->assertCount(7, $this->csvNumbers($r));
        $this->assertNull($r->headers->get('X-Export-Truncated'));
    }
}

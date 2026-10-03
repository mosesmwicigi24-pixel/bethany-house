<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelMessage;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\ShipmentAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Role hardening 4D: payment proofs, shipment attachments, chat attachments and
 * expense receipts are handed out as signed links that live five minutes at
 * most, issued by the existing endpoints only after their parent-record check.
 * The file route trusts the signature and nothing else.
 */
class SignedAttachmentUrlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        Storage::fake('local');
        Storage::fake('private');
    }

    private function user(string $role): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $u->assignRole(Role::findByName($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return $u->fresh();
    }

    /** Issue, then fetch the link with NO credentials, as a browser tab would. */
    private function issueAndFetch(string $issuer, string $expectBody): string
    {
        $res = $this->getJson($issuer)->assertOk();
        $url = (string) $res->json('url');
        $this->assertStringContainsString('signature=', $url);
        $this->assertNotNull($res->json('expires_at'));
        $this->assertLessThanOrEqual(5 * 60 + 5, now()->diffInSeconds(\Illuminate\Support\Carbon::parse($res->json('expires_at'))));

        $this->app['auth']->forgetGuards();
        $file = $this->withHeaders(['Authorization' => ''])->get($url);
        $file->assertOk();
        $this->assertSame($expectBody, $file->getContent());
        return $url;
    }

    public function test_a_payment_proof_link_works_for_five_minutes_and_not_after_or_altered(): void
    {
        $path = 'payment-proofs/slip.pdf';
        Storage::disk('local')->put($path, 'BANK SLIP');
        $payment = Payment::factory()->create([
            'order_id' => Order::factory()->create()->id, 'proof_of_payment_path' => $path,
            'requires_approval' => true, 'approval_status' => 'pending_review',
        ]);
        Sanctum::actingAs($this->user('finance_manager'));

        $url = $this->issueAndFetch("/api/v1/admin/payments/{$payment->id}/proof", 'BANK SLIP');

        $this->get($url . 'x')->assertForbidden();                                     // tampered
        $this->get(preg_replace('/signature=[^&]+/', 'signature=deadbeef', $url))->assertForbidden();
        $this->get(str_replace("payment-proofs/{$payment->id}", 'payment-proofs/' . ($payment->id + 1), $url))->assertForbidden();

        $this->travel(6)->minutes();
        $this->get($url)->assertForbidden();                                            // expired
    }

    public function test_no_link_is_issued_past_the_parent_check(): void
    {
        Storage::disk('local')->put('payment-proofs/x.pdf', 'X');
        $payment = Payment::factory()->create([
            'order_id' => Order::factory()->create()->id, 'proof_of_payment_path' => 'payment-proofs/x.pdf',
        ]);
        Sanctum::actingAs($this->user('procurement_manager'));   // payments.view, not the order

        $res = $this->getJson("/api/v1/admin/payments/{$payment->id}/proof")->assertNotFound();
        $this->assertNull($res->json('url'));
    }

    public function test_the_file_route_refuses_a_request_without_a_signature(): void
    {
        $payment = Payment::factory()->create(['order_id' => Order::factory()->create()->id]);
        $this->get("/api/v1/files/payment-proofs/{$payment->id}")->assertForbidden();
    }

    public function test_an_expense_receipt_is_issued_after_the_outlet_scope_check(): void
    {
        $outlet = Outlet::factory()->create();
        $other  = Outlet::factory()->create();
        $cat    = ExpenseCategory::create(['name' => 'Rent', 'code' => 'RENT']);
        $maker  = User::factory()->create();
        $mk = fn (int $outletId) => Expense::create([
            'reference_number' => 'EXP-' . uniqid(), 'title' => 'Rent', 'category_id' => $cat->id,
            'amount' => 100, 'currency_code' => 'KES', 'exchange_rate' => 1, 'amount_kes' => 100,
            'expense_date' => '2026-07-01', 'payment_method' => 'cash', 'outlet_id' => $outletId,
            'created_by' => $maker->id, 'status' => 'approved', 'receipt_path' => "expenses/r{$outletId}.jpg",
        ]);
        $mine = $mk($outlet->id);
        $theirs = $mk($other->id);
        Storage::disk('private')->put($mine->receipt_path, 'RECEIPT');
        Storage::disk('private')->put($theirs->receipt_path, 'THEIRS');

        $manager = $this->user('outlet_manager');
        $manager->outlets()->attach($outlet->id);
        Sanctum::actingAs($manager);

        $this->issueAndFetch("/api/v1/admin/expenses/{$mine->id}/receipt", 'RECEIPT');

        // Still an exempt download in the owner's ledger, against the manager,
        // without the link's signature.
        $dr = \App\Models\DownloadRequest::where('user_id', $manager->id)->latest('id')->first();
        $this->assertNotNull($dr, 'the receipt download is no longer recorded');
        $this->assertSame('exempt', $dr->category);
        $this->assertArrayNotHasKey('signature', (array) $dr->payload);

        Sanctum::actingAs($manager);
        $res = $this->getJson("/api/v1/admin/expenses/{$theirs->id}/receipt");
        $this->assertContains($res->status(), [403, 404]);
        $this->assertNull($res->json('url'));
    }

    public function test_shipment_attachments_are_issued_per_shipment(): void
    {
        $shipment = OrderShipment::create([
            'order_id' => Order::factory()->create()->id, 'shipment_number' => 'SHP-1',
            'status' => 'in_transit', 'tracking_token' => 'tok-1',
        ]);
        Storage::disk('local')->put('shipments/2026/10/a.pdf', 'WAYBILL');
        $att = ShipmentAttachment::create([
            'attachable_type' => 'shipment', 'attachable_id' => $shipment->id, 'shipment_id' => $shipment->id,
            'path' => 'shipments/2026/10/a.pdf', 'original_name' => 'waybill.pdf', 'is_public' => false,
        ]);
        Sanctum::actingAs($this->user('super_admin'));

        $this->issueAndFetch("/api/v1/admin/shipments/{$shipment->id}/attachments/{$att->id}", 'WAYBILL');

        Sanctum::actingAs($this->user('super_admin'));
        $this->getJson('/api/v1/admin/shipments/' . ($shipment->id + 99) . "/attachments/{$att->id}")->assertNotFound();
    }

    public function test_a_chat_attachment_is_issued_to_members_of_a_channel_that_carries_it(): void
    {
        $path = 'channel-attachments/2026/10/0b6f3c1e-aaaa-bbbb-cccc-123456789abc.jpg';
        Storage::disk('local')->put($path, 'PHOTO');

        $channel = Channel::create(['type' => 'space', 'name' => 'QC', 'is_private' => true]);
        $member  = $this->user('pos_clerk');
        $outsider = $this->user('pos_clerk');
        DB::table('channel_members')->insert(['channel_id' => $channel->id, 'user_id' => $member->id, 'created_at' => now(), 'updated_at' => now()]);
        ChannelMessage::create([
            'channel_id' => $channel->id, 'user_id' => $member->id, 'type' => 'text',
            'body' => '![photo](https://hub.test/api/v1/admin/channels/attachments/serve?path=' . urlencode($path) . ')',
        ]);

        Sanctum::actingAs($member);
        $this->issueAndFetch('/api/v1/admin/channels/attachments/serve?path=' . urlencode($path), 'PHOTO');

        // Staff who are not in the conversation got the file by path before.
        Sanctum::actingAs($outsider);
        $res = $this->getJson('/api/v1/admin/channels/attachments/serve?path=' . urlencode($path));
        $res->assertNotFound();
        $this->assertNull($res->json('url'));
    }

    public function test_the_uploader_can_open_a_chat_attachment_before_sending_it(): void
    {
        $u = $this->user('pos_clerk');
        Sanctum::actingAs($u);
        $up = $this->postJson('/api/v1/admin/channels/attachments', [
            'file' => \Illuminate\Http\UploadedFile::fake()->image('p.jpg'),
        ])->assertCreated();

        $res = $this->getJson('/api/v1/admin/channels/attachments/serve?path=' . urlencode($up->json('path')))->assertOk();
        $this->assertStringContainsString('signature=', (string) $res->json('url'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseApproval;
use App\Models\ExpenseAttachment;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Expense evidence (owner, 2026-10-16): photos of receipts, paper quotations,
 * bills and meters, several per expense, each labelled. Append-only: nothing
 * is overwritten or deleted; the uploader may withdraw a wrong file only
 * until someone acts on the expense with it in front of them.
 */
class ExpenseEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $shop;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        Storage::fake('private');
        $this->shop = Outlet::factory()->create(['name' => 'Nairobi shop']);
        $this->categoryId = DB::table('expense_categories')->insertGetId([
            'name' => 'Evidence test utilities', 'code' => 'EVUTIL', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function person(string $role, ?Outlet $outlet = null): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $u->assignRole(Role::findByName($role, 'sanctum'));
        $u->outlets()->attach(($outlet ?? $this->shop)->id, ['is_primary' => true]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function expense(User $maker, string $status = 'draft', ?Outlet $outlet = null): Expense
    {
        return Expense::create([
            'title' => 'Water — October', 'category_id' => $this->categoryId, 'amount' => 1850, 'currency_code' => 'KES',
            'exchange_rate' => 1, 'amount_kes' => 1850, 'expense_date' => now()->toDateString(), 'payment_method' => 'mpesa',
            'outlet_id' => ($outlet ?? $this->shop)->id, 'created_by' => $maker->id, 'status' => $status,
        ]);
    }

    private function add(Expense $e, string $kind = 'receipt', ?UploadedFile $file = null)
    {
        return $this->post("/api/v1/admin/expenses/{$e->id}/attachments", [
            'kind' => $kind, 'file' => $file ?? UploadedFile::fake()->image('photo.jpg', 800, 1200),
        ], ['Accept' => 'application/json']);
    }

    public function test_a_manager_adds_a_photo_of_the_receipt_and_of_the_paper_quotation(): void
    {
        $maker = $this->person('outlet_manager');
        Sanctum::actingAs($maker);
        $e = $this->expense($maker);

        $this->add($e, 'quotation')->assertCreated()->assertJsonPath('attachment.kind', 'quotation');
        $this->add($e, 'receipt')->assertCreated()->assertJsonPath('message', 'Receipt added.');
        $this->add($e, 'meter', UploadedFile::fake()->create('bill.pdf', 300, 'application/pdf'))->assertCreated();

        $show = $this->getJson("/api/v1/admin/expenses/{$e->id}")->assertOk();
        $this->assertCount(3, $show->json('expense.attachments'));
        $this->assertSame(['quotation', 'receipt', 'meter'], array_column($show->json('expense.attachments'), 'kind'));
        $this->assertArrayNotHasKey('path', $show->json('expense.attachments.0'), 'the storage path stays on the server');
        $this->assertTrue($show->json('expense.attachments.0.can_remove'));

        // The first receipt also fills the old single-receipt field for older screens.
        $receipt = ExpenseAttachment::where('expense_id', $e->id)->where('kind', 'receipt')->sole();
        $this->assertSame($receipt->path, $e->fresh()->receipt_path);
        Storage::disk('private')->assertExists($receipt->path);
        $this->assertDatabaseHas('activity_log', ['event' => 'expense_evidence_added', 'subject_id' => $e->id]);

        // The approver's card names it (summary built on read).
        $lines = app(\App\Services\Approvals\Handlers\ExpenseHandler::class)->summary($e->fresh())['lines'];
        $this->assertContains('Evidence: Quotation, Receipt, Meter reading (3 files)', $lines);

        // Not a document type: refused.
        $this->add($e, 'receipt', UploadedFile::fake()->create('x.exe', 10))->assertStatus(422);
        $this->add($e, 'selfie')->assertStatus(422);
    }

    public function test_replacing_a_receipt_no_longer_destroys_the_first_one(): void
    {
        $owner = $this->person('super_admin');
        Sanctum::actingAs($owner);
        $e = $this->expense($owner, 'paid');

        $first = $this->post("/api/v1/admin/expenses/{$e->id}/receipt", ['receipt' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])
            ->assertOk()->json('receipt_path');
        $this->post("/api/v1/admin/expenses/{$e->id}/receipt", ['receipt' => UploadedFile::fake()->image('b.jpg')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('receipt_path', $first);

        // Both files are kept and listed; the first is still the receipt older screens show.
        $paths = ExpenseAttachment::where('expense_id', $e->id)->pluck('path')->all();
        $this->assertCount(2, $paths);
        foreach ($paths as $p) {
            Storage::disk('private')->assertExists($p);
        }
    }

    public function test_the_uploader_withdraws_a_wrong_photo_and_it_stays_on_record(): void
    {
        $maker = $this->person('outlet_manager');
        Sanctum::actingAs($maker);
        $e = $this->expense($maker);
        $id = $this->add($e, 'receipt')->assertCreated()->json('attachment.id');
        $path = ExpenseAttachment::findOrFail($id)->path;

        $this->deleteJson("/api/v1/admin/expenses/{$e->id}/attachments/{$id}")->assertOk();

        $a = ExpenseAttachment::findOrFail($id);
        $this->assertNotNull($a->removed_at);
        $this->assertSame($maker->id, $a->removed_by);
        Storage::disk('private')->assertExists($path);   // the file is kept
        $this->assertNull($e->fresh()->receipt_path, 'a withdrawn receipt is no longer the receipt');
        $this->assertDatabaseHas('activity_log', ['event' => 'expense_evidence_removed', 'subject_id' => $e->id]);

        // Listed as removed, not hidden.
        $row = collect($this->getJson("/api/v1/admin/expenses/{$e->id}")->json('expense.attachments'))->firstWhere('id', $id);
        $this->assertNotNull($row['removed_at']);
        $this->assertFalse($row['can_remove']);
        $this->deleteJson("/api/v1/admin/expenses/{$e->id}/attachments/{$id}")->assertStatus(422);
    }

    public function test_evidence_someone_acted_on_stays(): void
    {
        $maker = $this->person('outlet_manager');
        $owner = $this->person('super_admin');
        Sanctum::actingAs($maker);
        $e = $this->expense($maker, 'pending_approval');
        $seen = $this->add($e, 'quotation')->assertCreated()->json('attachment.id');

        // The approver sends it back, with the quotation in front of them.
        $this->travel(1)->minutes();
        ExpenseApproval::create(['expense_id' => $e->id, 'approver_id' => $owner->id, 'action' => 'requested_info', 'acted_at' => now(), 'step' => 1]);
        $e->update(['status' => 'changes_requested']);

        $this->deleteJson("/api/v1/admin/expenses/{$e->id}/attachments/{$seen}")
            ->assertStatus(422)->assertJsonPath('message', 'Someone has already acted on this expense with this file. It stays as evidence.');

        // A photo added after that can still be withdrawn …
        $this->travel(1)->minutes();
        $later = $this->add($e, 'receipt')->assertCreated()->json('attachment.id');
        $this->deleteJson("/api/v1/admin/expenses/{$e->id}/attachments/{$later}")->assertOk();

        // … until the expense is decided. Adding evidence after approval is still allowed (append-only).
        $e->update(['status' => 'approved']);
        $paid = $this->add($e, 'receipt')->assertCreated()->json('attachment.id');
        $this->deleteJson("/api/v1/admin/expenses/{$e->id}/attachments/{$paid}")
            ->assertStatus(422)->assertJsonPath('message', 'Files on an expense that has been decided stay as evidence.');
    }

    public function test_only_the_uploader_may_withdraw_and_only_ten_files(): void
    {
        $maker = $this->person('outlet_manager');
        $other = $this->person('outlet_manager');
        Sanctum::actingAs($maker);
        $e = $this->expense($maker);
        $id = $this->add($e)->assertCreated()->json('attachment.id');

        Sanctum::actingAs($other);
        $this->deleteJson("/api/v1/admin/expenses/{$e->id}/attachments/{$id}")
            ->assertStatus(422)->assertJsonPath('message', 'Only the person who added a file can remove it.');

        Sanctum::actingAs($maker);
        for ($i = 1; $i < ExpenseAttachment::MAX_PER_EXPENSE; $i++) {
            $this->add($e, 'other')->assertCreated();
        }
        $this->add($e, 'other')->assertStatus(422);
    }

    public function test_a_file_is_opened_through_a_short_lived_link_after_the_scope_check(): void
    {
        $elsewhere = Outlet::factory()->create();
        $manager = $this->person('outlet_manager');
        $stranger = $this->person('outlet_manager', $elsewhere);
        Sanctum::actingAs($manager);
        $e = $this->expense($manager);
        $id = $this->add($e, 'receipt', UploadedFile::fake()->createWithContent('r.jpg', 'RECEIPT-BYTES'))->assertCreated()->json('attachment.id');

        $url = $this->getJson("/api/v1/admin/expenses/{$e->id}/attachments/{$id}")->assertOk()->json('url');
        $this->assertStringContainsString('signature=', $url);
        $this->app['auth']->forgetGuards();
        $file = $this->withHeaders(['Authorization' => ''])->get($url)->assertOk();
        $this->assertSame('RECEIPT-BYTES', $file->getContent());
        $dr = \App\Models\DownloadRequest::where('user_id', $manager->id)->latest('id')->first();
        $this->assertSame('exempt', $dr?->category, 'recorded for the owner, never held');

        // Another outlet's manager gets no link, and cannot add to it either.
        Sanctum::actingAs($stranger);
        $this->assertContains($this->getJson("/api/v1/admin/expenses/{$e->id}/attachments/{$id}")->status(), [403, 404]);
        $this->assertContains($this->add($e)->status(), [403, 404]);
    }

    public function test_existing_receipts_are_copied_in_as_the_first_attachment(): void
    {
        $owner = $this->person('super_admin');
        $e = $this->expense($owner, 'paid');
        $e->update(['receipt_path' => 'expenses/old/receipt.jpg']);
        $plain = $this->expense($owner, 'paid');

        // Re-run the migration over these rows.
        Schema::drop('expense_attachments');
        (require base_path('database/migrations/2026_10_16_090000_create_expense_attachments.php'))->up();

        $a = ExpenseAttachment::where('expense_id', $e->id)->sole();
        $this->assertSame('receipt', $a->kind);
        $this->assertSame('expenses/old/receipt.jpg', $a->path);
        $this->assertSame($owner->id, $a->uploaded_by);
        $this->assertSame('expenses/old/receipt.jpg', $e->fresh()->receipt_path, 'the old field is untouched');
        $this->assertSame(0, ExpenseAttachment::where('expense_id', $plain->id)->count());
    }
}

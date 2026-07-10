<?php

namespace Tests\Feature;

use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\SalesDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class QuotationApiTest extends TestCase
{
    use RefreshDatabase;

    private function actor(array $perms = ['quotations.view', 'quotations.create', 'quotations.issue', 'quotations.delete']): void
    {
        $user = User::factory()->create();
        foreach ($perms as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
    }

    private function createQuotation(): int
    {
        $res = $this->postJson('/api/v1/admin/quotations', [
            'customer_first_name' => 'Jane',
            'customer_email'      => 'jane@example.com',
            'valid_until'         => now()->addDays(14)->toDateString(),
            'items' => [
                ['product_name' => 'Custom sofa', 'quantity' => 2, 'unit_price' => 15000],
                ['product_name' => 'Delivery',    'quantity' => 1, 'unit_price' => 2000],
            ],
        ]);
        $res->assertCreated();
        return $res->json('quotation.id');
    }

    public function test_create_builds_lines_and_totals_as_a_draft(): void
    {
        $this->actor();
        $id = $this->createQuotation();

        $this->assertDatabaseHas('quotations', ['id' => $id, 'status' => 'draft', 'quote_number' => null]);
        $this->assertSame(2, QuotationItem::where('quotation_id', $id)->count());
        $this->assertGreaterThan(0, (float) Quotation::find($id)->total_amount);
    }

    public function test_shipping_and_served_by_are_recorded_and_shipping_is_in_the_total(): void
    {
        $this->actor();

        $id = $this->postJson('/api/v1/admin/quotations', [
            'currency_code'   => 'USD',
            'shipping_amount' => 500,
            'served_by'       => 'Grace at the counter',
            'items' => [['product_name' => 'Ad-hoc item', 'quantity' => 1, 'unit_price' => 1000]],
        ])->assertCreated()->json('quotation.id');

        $q = Quotation::find($id);
        $this->assertSame('USD', $q->currency_code);
        $this->assertSame('Grace at the counter', $q->served_by);
        $this->assertSame(500.0, (float) $q->shipping_amount);
        // No product → no tax; total = line 1000 + shipping 500.
        $this->assertSame(1500.0, (float) $q->total_amount);
    }

    public function test_issue_assigns_a_gapless_number_and_freezes_a_snapshot(): void
    {
        $this->actor();
        $id = $this->createQuotation();

        $res = $this->postJson("/api/v1/admin/quotations/{$id}/issue")->assertOk();
        $number = $res->json('document.number');

        $this->assertStringStartsWith('QUO-', $number);
        $this->assertDatabaseHas('quotations', ['id' => $id, 'status' => 'sent', 'quote_number' => $number]);

        $doc = SalesDocument::where('number', $number)->first();
        $this->assertNotNull($doc);
        $this->assertSame('quotation', $doc->type);
        $this->assertSame($id, (int) $doc->documentable_id);
        $this->assertCount(2, $doc->snapshot['items']);           // frozen lines
        $this->assertSame('Jane', $doc->snapshot['customer']['first_name']);
    }

    public function test_an_issued_quotation_cannot_be_edited(): void
    {
        $this->actor();
        $id = $this->createQuotation();
        $this->postJson("/api/v1/admin/quotations/{$id}/issue")->assertOk();

        $this->putJson("/api/v1/admin/quotations/{$id}", [
            'items' => [['product_name' => 'sneaky change', 'quantity' => 1, 'unit_price' => 1]],
        ])->assertStatus(422);
    }

    public function test_issue_is_idempotent(): void
    {
        $this->actor();
        $id = $this->createQuotation();

        $n1 = $this->postJson("/api/v1/admin/quotations/{$id}/issue")->json('document.number');
        $n2 = $this->postJson("/api/v1/admin/quotations/{$id}/issue")->json('document.number');

        $this->assertSame($n1, $n2);
        $this->assertSame(1, SalesDocument::where('documentable_id', $id)->where('type', 'quotation')->count());
    }

    public function test_numbers_are_sequential_across_quotations(): void
    {
        $this->actor();
        $y = now()->format('Y');

        $a = $this->createQuotation();
        $b = $this->createQuotation();
        $na = $this->postJson("/api/v1/admin/quotations/{$a}/issue")->json('document.number');
        $nb = $this->postJson("/api/v1/admin/quotations/{$b}/issue")->json('document.number');

        $this->assertSame("QUO-{$y}-0001", $na);
        $this->assertSame("QUO-{$y}-0002", $nb);
    }

    public function test_create_requires_quotations_create_permission(): void
    {
        $this->actor(['quotations.view']);   // view only
        $this->postJson('/api/v1/admin/quotations', [
            'items' => [['product_name' => 'x', 'quantity' => 1, 'unit_price' => 1]],
        ])->assertStatus(403);
    }
}

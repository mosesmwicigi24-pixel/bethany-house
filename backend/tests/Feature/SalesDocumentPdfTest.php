<?php

namespace Tests\Feature;

use App\Models\SalesDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SalesDocumentPdfTest extends TestCase
{
    use RefreshDatabase;

    private function actor(array $perms): void
    {
        $user = User::factory()->create();
        foreach ($perms as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
    }

    public function test_quotation_pdf_renders(): void
    {
        $this->actor(['quotations.view', 'quotations.create', 'quotations.issue']);

        $id = $this->postJson('/api/v1/admin/quotations', [
            'customer_first_name' => 'Jane',
            'valid_until'         => now()->addDays(14)->toDateString(),
            'items' => [['product_name' => 'Custom sofa', 'quantity' => 2, 'unit_price' => 15000]],
        ])->assertCreated()->json('quotation.id');
        $this->postJson("/api/v1/admin/quotations/{$id}/issue")->assertOk();

        $res = $this->get("/api/v1/admin/pdf/quotations/{$id}");
        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    public function test_receipt_pdf_renders_from_its_snapshot(): void
    {
        $this->actor(['orders.view']);

        $doc = SalesDocument::create([
            'type'          => SalesDocument::RECEIPT,
            'number'        => 'RCP-2026-0001',
            'issued_at'     => now(),
            'status'        => 'issued',
            'amount'        => 1000,
            'currency_code' => 'KES',
            'snapshot'      => [
                'receipt_number' => 'RCP-2026-0001',
                'order_number'   => 'ORD-ABC123',
                'issued_at'      => now()->toIso8601String(),
                'currency_code'  => 'KES',
                'payment'        => ['method' => 'cash', 'reference' => 'REF1', 'amount' => 1000],
                'invoice_total'  => 3000,
                'paid_to_date'   => 1000,
                'balance_due'    => 2000,
                'fully_paid'     => false,
            ],
        ]);

        $res = $this->get("/api/v1/admin/pdf/receipts/{$doc->id}");
        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
    }
}

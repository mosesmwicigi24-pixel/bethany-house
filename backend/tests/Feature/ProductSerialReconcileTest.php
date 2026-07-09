<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSerial;
use App\Services\ProductSerialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4: reconciliation compares a physical count against system stock and
 * flags what's missing (loss/theft signal).
 */
class ProductSerialReconcileTest extends TestCase
{
    use RefreshDatabase;

    private function inStock(int $productId, string $serial): ProductSerial
    {
        return ProductSerial::create([
            'serial_number' => $serial,
            'product_id'    => $productId,
            'status'        => ProductSerial::IN_STOCK,
            'stocked_at'    => now(),
        ]);
    }

    public function test_reconcile_reports_missing_and_unexpected(): void
    {
        $product = Product::factory()->create();
        $this->inStock($product->id, 'A-1');
        $this->inStock($product->id, 'A-2');
        $this->inStock($product->id, 'A-3');

        // Physically found A-1, A-2, and a stray X-9. A-3 is missing.
        $report = ProductSerialService::reconcile($product->id, null, ['A-1', 'A-2', 'X-9']);

        $this->assertCount(2, $report['matched']);
        $this->assertCount(1, $report['missing']);
        $this->assertSame('A-3', $report['missing'][0]['serial_number']);
        $this->assertSame(['X-9'], $report['unexpected']);
    }

    public function test_reconcile_can_flag_missing_as_lost(): void
    {
        $product = Product::factory()->create();
        $keep = $this->inStock($product->id, 'B-1');
        $lost = $this->inStock($product->id, 'B-2');

        ProductSerialService::reconcile($product->id, null, ['B-1'], flagMissing: true);

        $this->assertSame(ProductSerial::IN_STOCK, $keep->fresh()->status);
        $this->assertSame(ProductSerial::MISSING, $lost->fresh()->status);
    }
}

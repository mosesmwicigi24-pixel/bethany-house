<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Characterization tests for App\Models\InventoryItem::adjustQuantity — the core
 * stock-mutation primitive. These lock in CURRENT behavior so the upcoming
 * inventory-ledger unification (audit finding INV-1/INV-4) can be verified not
 * to change it unintentionally.
 */
class InventoryAdjustQuantityTest extends TestCase
{
    use RefreshDatabase;

    public function test_positive_adjustment_increments_and_logs_a_transaction(): void
    {
        $item = InventoryItem::factory()->create(['quantity_on_hand' => 50]);

        $item->adjustQuantity(10, 'purchase');

        $this->assertSame(60, $item->fresh()->quantity_on_hand);
        $this->assertDatabaseHas('inventory_transactions', [
            'inventory_item_id' => $item->id,
            'transaction_type'  => 'purchase',
            'quantity_change'   => 10,
            'quantity_before'   => 50,
            'quantity_after'    => 60,
        ]);
    }

    public function test_negative_adjustment_decrements_within_available_stock(): void
    {
        $item = InventoryItem::factory()->create(['quantity_on_hand' => 50]);

        $item->adjustQuantity(-20, 'sale');

        $this->assertSame(30, $item->fresh()->quantity_on_hand);
        $this->assertDatabaseHas('inventory_transactions', [
            'inventory_item_id' => $item->id,
            'transaction_type'  => 'sale',
            'quantity_change'   => -20,
            'quantity_after'    => 30,
        ]);
    }

    /**
     * Documents that adjustQuantity has NO application-layer floor: overselling
     * below zero hits the DB CHECK (quantity_on_hand >= 0) and throws a raw
     * QueryException, which surfaces to the client as a 500. The inventory fix
     * should guard this in-app and return a clean 422 — when it does, this test
     * gets updated to assert the graceful path.
     */
    public function test_oversell_below_zero_hits_the_db_check_constraint(): void
    {
        $item = InventoryItem::factory()->create(['quantity_on_hand' => 5]);

        $this->expectException(QueryException::class);

        $item->adjustQuantity(-10, 'sale');
    }
}

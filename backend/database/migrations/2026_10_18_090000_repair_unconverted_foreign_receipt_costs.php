<?php

use App\Models\Material;
use App\Models\PurchaseOrderItem;
use App\Services\ActivityLogService;
use App\Services\Procurement\LatestCost;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repair stock costs that receiving wrote unconverted (fixed in #464).
 *
 * Until #464, receiving a foreign-currency PO set the item's latest cost to
 * the PO price as-is: a USD 10.00 metre became a KES 10.00 cost on
 * materials.unit_cost (or the legacy inventories.cost_per_unit for a
 * product). This restates exactly those rows in KES at the reporting rate —
 * the same LatestCost rule receiving now applies.
 *
 * A row is repaired only when ALL hold, so nothing set by anyone else moves:
 *   - the item's LATEST receipt was on a non-KES PO with a reporting rate;
 *   - its stored cost still equals that PO's foreign price (never converted,
 *     and not changed since);
 *   - the price is above zero.
 * Each repair is written to the activity log with the old and new cost, the
 * PO and the GRN. Re-running finds nothing (the cost no longer equals the
 * foreign price). Cost of sales is untouched: order lines keep the cost they
 * were sold at.
 *
 * down() does not reverse it: the old figure was wrong, and each one is in
 * the activity log.
 */
return new class extends Migration
{
    public function up(): void
    {
        $repaired = 0;
        foreach (['material', 'product'] as $kind) {
            foreach ($this->latestForeignReceipts($kind) as $r) {
                $repaired += $this->repair($kind, $r);
            }
        }

        if ($repaired > 0) {
            echo "  Restated {$repaired} stock cost(s) in KES at the reporting rate.\n";
        }
    }

    public function down(): void
    {
        // Intentionally not reversed — see the docblock.
    }

    /** The latest receipt per item, kept only when its PO was not in KES. */
    private function latestForeignReceipts(string $kind): array
    {
        $key = $kind === 'material' ? 'pi.material_id' : 'pi.product_id, pi.product_variant_id';

        return DB::select("
            SELECT * FROM (
                SELECT DISTINCT ON ({$key})
                       pi.id AS po_item_id, pi.unit_price, po.currency_code, po.po_number, g.grn_number
                FROM grn_items gi
                JOIN goods_received_notes g  ON g.id  = gi.grn_id
                JOIN purchase_order_items pi ON pi.id = gi.po_item_id
                JOIN purchase_orders po      ON po.id = pi.purchase_order_id
                WHERE pi.item_type = ? AND gi.quantity_received > COALESCE(gi.quantity_rejected, 0)
                ORDER BY {$key}, g.received_date DESC, gi.id DESC
            ) latest
            WHERE UPPER(currency_code) <> 'KES' AND unit_price > 0
        ", [$kind]);
    }

    private function repair(string $kind, object $r): int
    {
        $line  = PurchaseOrderItem::find($r->po_item_id);
        $price = round((float) $r->unit_price, 2);
        $kes   = LatestCost::kes($price, $r->currency_code);
        if (!$line || $kes === null) {
            return 0;   // no reporting rate: left as it is, never a guess
        }

        if ($kind === 'material') {
            $m = Material::find($line->material_id);
            if (!$m || round((float) $m->unit_cost, 2) !== $price) {
                return 0;
            }
            $old = (float) $m->unit_cost;
            $subject = $m;
        } else {
            $rows = DB::table('inventories')->where('inventory_type', 'product')
                ->where('product_id', $line->product_id)
                ->when($line->product_variant_id, fn ($q) => $q->where('product_variant_id', $line->product_variant_id))
                ->get(['cost_per_unit']);
            if ($rows->isEmpty() || $rows->contains(fn ($x) => round((float) $x->cost_per_unit, 2) !== $price)) {
                return 0;
            }
            $old = $price;
            $subject = $line;
        }

        LatestCost::set($line, $kes);
        ActivityLogService::log('stock_cost_restated_fx', $subject, [
            'kind'        => $kind,
            'old_cost'    => $old,
            'new_cost'    => $kes,
            'po_number'   => $r->po_number,
            'grn_number'  => $r->grn_number,
            'po_currency' => strtoupper($r->currency_code),
            'po_price'    => $price,
        ], "Stock cost restated from {$old} to KES {$kes}: {$r->po_number} was in " . strtoupper($r->currency_code) . ' and receiving had not converted it');

        return 1;
    }
};

<?php

namespace App\Services\Approvals\Handlers;

use App\Models\ChangeProposal;
use App\Models\ProductPrice;
use App\Models\User;
use App\Support\CostBasis;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * product_cost_change — the KES cost price on a product's price row (maker:
 * procurement_manager, products.edit_cost; admin holds it through products.*).
 *
 *   change ≤ 5 % (against the cost 24 h ago) → applies at once
 *   ≤ 25 %                                   → + finance
 *   above                                    → + super admin
 *
 * A first cost (none on the book before) measures 0: entering a missing figure
 * is not a change to one. Clearing a cost measures 100 %.
 *
 * Historical COGS is never rewritten. A sold line keeps the cost snapshotted
 * on it at sale (order_items.cost_price); a line sold while the book had no
 * cost has no snapshot and is costed through the book (App\Support\CostBasis)
 * — so before the book changes, every such line that the OLD cost currently
 * prices is frozen at that cost (cost_source 'book_frozen'). Its figure in
 * every report is the same number before and after; only future sales see the
 * new cost.
 */
final class ProductCostChangeHandler extends ProposalHandler
{
    public const FROZEN_SOURCE = 'book_frozen';

    public function event(): string { return 'product_cost_change'; }
    public function subjectType(): string { return 'product_price'; }
    public function title(): string { return 'Product cost'; }
    public function directEvent(): ?string { return 'product_cost_change_direct'; }
    public function unit(): string { return 'percent'; }
    public function makerPermissions(): array { return ['products.edit_cost']; }

    public function fields(): array
    {
        return ['cost_price' => 'Cost (KES)'];
    }

    public function current(int $subjectId): ?array
    {
        $row = ProductPrice::find($subjectId);

        return $row ? ['cost_price' => $row->cost_price === null ? null : (float) $row->cost_price] : null;
    }

    public function label(int $subjectId): string
    {
        return PriceRowLabel::of($subjectId);
    }

    public function normalise(array $new, int $subjectId): array
    {
        $out = array_intersect_key($new, $this->fields());
        $row = ProductPrice::find($subjectId);
        if ($row && strtoupper((string) $row->currency_code) !== 'KES' && array_key_exists('cost_price', $out)) {
            throw ValidationException::withMessages(['cost_price' => 'Cost is kept on the KES price row only.']);
        }
        if (array_key_exists('cost_price', $out)) {
            $c = $out['cost_price'];
            if ($c !== null && (!is_numeric($c) || (float) $c < 0)) {
                throw ValidationException::withMessages(['cost_price' => 'The cost must be a number, zero or more.']);
            }
            $out['cost_price'] = $c === null ? null : round((float) $c, 2);
        }

        return $out;
    }

    public function measure(ChangeProposal $p): array
    {
        $live = $this->current((int) $p->subject_id) ?? [];
        $new  = $p->changeset['cost_price']['new'] ?? null;
        $pct  = self::changePct($this->baseline($p, 'cost_price', $live['cost_price'] ?? null), $new);

        return ['direct_basis' => $pct, 'direct_ok' => true, 'band_basis' => $pct, 'change_pct' => $pct];
    }

    public function apply(ChangeProposal $p, array $new, ?User $by): void
    {
        $row = ProductPrice::whereKey($p->subject_id)->lockForUpdate()->firstOrFail();
        if (!array_key_exists('cost_price', $new)) {
            return;
        }
        self::freezeHistory((int) $row->product_id, $row->product_variant_id ? (int) $row->product_variant_id : null,
            $row->cost_price === null ? null : (float) $row->cost_price);

        $row->cost_price = $new['cost_price'];
        $row->save();
    }

    /**
     * Snapshot, on every sold line that has none, the cost the book gives it
     * now — but only lines the changing row prices (their book cost equals the
     * row's old cost). Returns how many lines were frozen.
     */
    public static function freezeHistory(int $productId, ?int $variantId, ?float $oldCost): int
    {
        if ($oldCost === null) {
            return 0;   // no old cost: those lines had none to keep
        }
        $book = CostBasis::bookCostSql('oi');

        return DB::update(
            "UPDATE order_items AS oi
                SET cost_price = {$book}, cost_source = ?
              WHERE oi.cost_price IS NULL
                AND oi.product_id = ?
                " . ($variantId !== null ? 'AND oi.product_variant_id = ?' : '') . "
                AND {$book} = ?",
            array_values(array_filter([self::FROZEN_SOURCE, $productId, $variantId, $oldCost], fn ($v) => $v !== null)),
        );
    }

    public function link(ChangeProposal $p): ?string
    {
        $productId = ProductPrice::whereKey($p->subject_id)->value('product_id');

        return $productId ? "/catalogue/products/{$productId}" : null;
    }

    public function formatValue(string $field, mixed $value, ChangeProposal $p): string
    {
        return $value === null ? '—' : 'KES ' . number_format((float) $value, 2);
    }

    public function measureLine(ChangeProposal $p): ?string
    {
        $pct = $p->measures['change_pct'] ?? null;

        return $pct === null ? null : 'Change ' . number_format((float) $pct, 1) . '% from the cost 24 hours ago';
    }
}

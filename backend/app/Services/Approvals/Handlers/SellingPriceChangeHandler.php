<?php

namespace App\Services\Approvals\Handlers;

use App\Models\ChangeProposal;
use App\Models\ProductPrice;
use App\Models\User;
use App\Support\CostBasis;
use App\Support\ReportingCurrency;
use Illuminate\Validation\ValidationException;

/**
 * selling_price_change — a price row's regular or sale price (maker: admin,
 * products.edit).
 *
 *   change ≤ 10 % (against the price 24 h ago) and not below cost → applies at once
 *   more than 10 %, or below cost                                  → + finance
 *   more than 20 % below cost                                      → + super admin
 *
 * Two measures, so two threshold events: selling_price_change_direct holds the
 * 10 % (change), selling_price_change holds the 20 % (below cost) — a change
 * over 10 % that stays at or above cost measures 0 there, finance alone.
 *
 * "Below cost" is judged on the lowest price the row can charge (the sale
 * price when one is set), stated in KES at the reporting rate, against the KES
 * book cost (App\Support\CostBasis — the same lookup COGS uses). No cost on
 * the book, or no reporting rate for the currency, and the change cannot be
 * judged: every band, never a guess (the engine's rule).
 */
final class SellingPriceChangeHandler extends ProposalHandler
{
    public function event(): string { return 'selling_price_change'; }
    public function subjectType(): string { return 'product_price'; }
    public function title(): string { return 'Selling price'; }
    public function directEvent(): ?string { return 'selling_price_change_direct'; }
    public function unit(): string { return 'percent'; }
    public function makerPermissions(): array { return ['products.edit']; }

    public function fields(): array
    {
        return ['regular_price' => 'Regular price', 'sale_price' => 'Sale price'];
    }

    public function current(int $subjectId): ?array
    {
        $row = ProductPrice::find($subjectId);

        return $row ? [
            'regular_price' => $row->regular_price === null ? null : (float) $row->regular_price,
            'sale_price'    => $row->sale_price === null ? null : (float) $row->sale_price,
        ] : null;
    }

    public function label(int $subjectId): string
    {
        return PriceRowLabel::of($subjectId);
    }

    public function normalise(array $new, int $subjectId): array
    {
        $out = array_intersect_key($new, $this->fields());
        if (array_key_exists('regular_price', $out)) {
            if ($out['regular_price'] === null || !is_numeric($out['regular_price']) || (float) $out['regular_price'] < 0) {
                throw ValidationException::withMessages(['regular_price' => 'The regular price must be a number, zero or more.']);
            }
            $out['regular_price'] = round((float) $out['regular_price'], 2);
        }
        if (array_key_exists('sale_price', $out)) {
            $sale = $out['sale_price'];
            if ($sale !== null && (!is_numeric($sale) || (float) $sale < 0)) {
                throw ValidationException::withMessages(['sale_price' => 'The sale price must be a number, zero or more.']);
            }
            // 0 means "no sale" (App\Models\ProductPrice).
            $out['sale_price'] = ($sale === null || (float) $sale <= 0) ? null : round((float) $sale, 2);
        }

        // The resulting row must be one the model will save: a sale below the regular price.
        $live    = $this->current($subjectId) ?? [];
        $regular = $out['regular_price'] ?? $live['regular_price'] ?? 0;
        $sale    = array_key_exists('sale_price', $out) ? $out['sale_price'] : ($live['sale_price'] ?? null);
        if ($sale !== null && $sale >= $regular) {
            throw ValidationException::withMessages(['sale_price' => 'The sale price must be lower than the regular price. Leave it empty when the item is not on sale.']);
        }

        return $out;
    }

    /** [cost KES, KES per unit of the row's currency] — either may be null. */
    private function costAndRate(int $subjectId): array
    {
        $row = ProductPrice::find($subjectId);
        if (!$row) {
            return [null, null];
        }
        $cost = CostBasis::unitCostFor((int) $row->product_id, $row->product_variant_id ? (int) $row->product_variant_id : null);
        $rate = ReportingCurrency::toKes(1.0, (string) $row->currency_code);

        return [$cost !== null && $cost > 0 ? $cost : null, $rate];
    }

    public function measure(ChangeProposal $p): array
    {
        $live = $this->current((int) $p->subject_id) ?? [];
        $new  = array_map(fn ($c) => $c['new'] ?? null, $p->changeset);

        $regular = array_key_exists('regular_price', $new) ? $new['regular_price'] : ($live['regular_price'] ?? null);
        $sale    = array_key_exists('sale_price', $new) ? $new['sale_price'] : ($live['sale_price'] ?? null);

        // How far the price moved from where this maker found it 24 h ago.
        $changes = [];
        if (array_key_exists('regular_price', $new)) {
            $changes['regular_price'] = self::changePct($this->baseline($p, 'regular_price', $live['regular_price'] ?? null), $new['regular_price']);
        }
        if (array_key_exists('sale_price', $new)) {
            $was = $this->baseline($p, 'sale_price', $live['sale_price'] ?? null);
            if ($new['sale_price'] === null) {
                $changes['sale_price'] = 0.0;                 // ending a sale never lowers a price
            } elseif ($was === null) {
                // A new sale: the cut from the regular price.
                $changes['sale_price'] = $regular > 0 ? round(($regular - $new['sale_price']) / $regular * 100, 2) : 100.0;
            } else {
                $changes['sale_price'] = self::changePct($was, $new['sale_price']);
            }
        }

        [$cost, $rate] = $this->costAndRate((int) $p->subject_id);
        $lowest    = $sale !== null && $sale > 0 && $sale < (float) $regular ? (float) $sale : (float) $regular;
        $lowestKes = $rate === null ? null : round($lowest * $rate, 2);
        $belowCost = ($cost === null || $lowestKes === null) ? null : max(0.0, round(($cost - $lowestKes) / $cost * 100, 2));

        return [
            'direct_basis'      => $changes === [] ? 0.0 : max($changes),
            // Can only go straight through when it is known NOT to be below cost.
            'direct_ok'         => $belowCost !== null && $belowCost <= 0.0,
            'band_basis'        => $belowCost,
            'change_pct'        => $changes,
            'cost_kes'          => $cost,
            'lowest_price_kes'  => $lowestKes,
            'below_cost_pct'    => $belowCost,
            'unknown_reason'    => $cost === null ? 'no cost on the book' : ($rate === null ? 'no reporting rate for the currency' : null),
        ];
    }

    public function context(ChangeProposal $p): array
    {
        [$cost, $rate] = $this->costAndRate((int) $p->subject_id);

        return ['cost_kes' => $cost, 'rate' => $rate];
    }

    public function apply(ChangeProposal $p, array $new, ?User $by): void
    {
        $row = ProductPrice::whereKey($p->subject_id)->lockForUpdate()->firstOrFail();
        $row->fill(array_intersect_key($new, $this->fields()));
        // The model's own rules still hold (sale below regular; the owner's 5%),
        // judged on the MAKER's discretion: a markdown the owner proposed stays
        // his when finance signs it (App\Support\DiscountRule::judgingAs()).
        $maker = $p->maker_id ? User::find($p->maker_id) : $by;
        \App\Support\DiscountRule::judgingAs($maker ?? $by, fn () => $row->save());
    }

    public function link(ChangeProposal $p): ?string
    {
        $productId = ProductPrice::whereKey($p->subject_id)->value('product_id');

        return $productId ? "/catalogue/products/{$productId}" : null;
    }

    public function formatValue(string $field, mixed $value, ChangeProposal $p): string
    {
        $currency = ProductPrice::whereKey($p->subject_id)->value('currency_code') ?? '';

        return $value === null ? '—' : trim($currency . ' ' . number_format((float) $value, 2));
    }

    public function measureLine(ChangeProposal $p): ?string
    {
        $m = $p->measures ?? [];
        $change = isset($m['direct_basis']) ? 'Change ' . number_format((float) $m['direct_basis'], 1) . '%' : null;
        if (($m['below_cost_pct'] ?? null) === null) {
            return trim(($change ? "{$change} · " : '') . 'Cannot tell whether it is below cost (' . ($m['unknown_reason'] ?? 'unknown') . ') — every band');
        }
        $cost = 'cost KES ' . number_format((float) $m['cost_kes'], 2);

        return ($change ? "{$change} · " : '') . ((float) $m['below_cost_pct'] > 0
            ? number_format((float) $m['below_cost_pct'], 1) . "% below {$cost}"
            : "at or above {$cost}");
    }
}

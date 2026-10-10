<?php

namespace App\Support;

use App\Exceptions\DiscountAboveMaximum;
use App\Models\User;

/**
 * One order's reductions, measured together (App\Support\DiscountRule).
 *
 * The limit is a statement about the ORDER. Capping each line and the cart
 * separately let a sale lose nearly 9.75% at a 5% limit (5% off the lines,
 * then 5% off what was left). A tally adds up everything taken off — line
 * discounts, the shortfall of a typed price under the catalogue, a promotion
 * the hub applied, the cart or order discount — and holds the total to the sum
 * of each line's own ceiling: the person's limit (DiscountRule::capFor(), per
 * role since 2026-10-10) of the order's gross before any discount, plus
 * whatever a running owner-set promotion is worth above that on the lines it
 * covers. The limit is read when the tally is judged, for the person judged.
 *
 * Entries are counted in the order they are added, and the one that pushes the
 * running total over is the field named in the refusal. Callers add what was
 * already there first (an order's existing discount, untouched lines) and what
 * is being asked for last, so the field named is the one that tipped it.
 */
final class DiscountTally
{
    /** @var list<array{0: float, 1: string}> [given, field] */
    private array $entries = [];
    /** @var list<array{0: float, 1: float}> [base, promotion saving] */
    private array $lines   = [];
    private float $gross   = 0.0;
    private float $given   = 0.0;

    /**
     * A line: what it gives away, what it is worth before any reduction, and
     * the promotion saving it may carry above the limit.
     */
    public function line(float $given, float $base, string $field, float $promotionSaving = 0.0): self
    {
        $base = max(0.0, $base);
        $this->gross   += $base;
        $this->lines[]  = [$base, $promotionSaving];

        return $this->amount($given, $field);
    }

    /** A reduction on the order as a whole — a cart or order discount. */
    public function amount(float $given, string $field): self
    {
        $given = round(max(0.0, $given), 2);
        $this->entries[] = [$given, $field];
        $this->given    += $given;

        return $this;
    }

    /** Total given away as a share of the gross (0 when there is no gross). */
    public function share(): float
    {
        return $this->gross > 0 ? $this->given / $this->gross : ($this->given > 0 ? INF : 0.0);
    }

    /** Null when the order is within its ceiling (or the caller is the owner), else the field that tipped it. */
    public function tippingField(?User $user): ?string
    {
        $cap = DiscountRule::capFor($user);

        return $cap === null ? null : $this->tippingFieldAt($cap);
    }

    /** The field that tipped the order over $cap percent, or null. */
    private function tippingFieldAt(float $cap): ?string
    {
        $ceiling = 0.0;
        foreach ($this->lines as [$base, $promotionSaving]) {
            $ceiling += max($base * $cap / 100, $promotionSaving);
        }
        $ceiling = round($ceiling, 2);
        $running = 0.0;
        foreach ($this->entries as [$given, $field]) {
            $running = round($running + $given, 2);
            if ($given > 0 && $running > $ceiling) {
                return $field;
            }
        }

        return null;
    }

    /** 422 naming the field that tipped the order over the person's limit, in their limit's words. */
    public function assert(?User $user): void
    {
        $cap = DiscountRule::capFor($user);
        if ($cap !== null && ($field = $this->tippingFieldAt($cap))) {
            throw new DiscountAboveMaximum($field, DiscountRule::limitMessage($cap));
        }
    }
}

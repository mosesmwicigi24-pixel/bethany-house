<?php

namespace App\Services\Expenses;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What the New Expense form suggests (owner, 2026-10-16): "look at all the
 * expenses already entered … find a pattern … placeholders in different
 * fields based on the common inputs."
 *
 * Derived at read time from the expenses the person may already see (the
 * caller hands in the list's own scoped query), the last 12 months, leaving
 * out rejected and cancelled ones — they were wrong. Nothing is stored.
 *
 * It only ever SUGGESTS. The form shows suggestions as grey text that the
 * person accepts or types over; the server validates the saved expense
 * exactly as before. Deliberately never suggested: the amount as a value
 * (it is shown, and must be accepted with its own tap), whether it came out
 * of the imprest (a deliberate choice — owner, 2026-09-22), the date, and
 * payment references (one per payment).
 */
class ExpenseSuggestions
{
    public const WINDOW_DAYS = 365;
    public const MAX_USUALS  = 8;
    public const RECENT_DAYS = 45;

    private const MONTHS = [
        'january', 'february', 'march', 'april', 'may', 'june',
        'july', 'august', 'september', 'october', 'november', 'december',
    ];

    /**
     * A title with its dates taken out, so "Water – September 2026" and
     * "water bill Oct" fall together. The console normalises the same way
     * (react-admin lib/expenseSuggestions.ts) to match what is typed.
     */
    public static function normalise(?string $title): string
    {
        $t = mb_strtolower(trim((string) $title));
        $months = implode('|', array_merge(self::MONTHS, ['jan', 'feb', 'mar', 'apr', 'jun', 'jul', 'aug', 'sept', 'sep', 'oct', 'nov', 'dec']));
        $t = preg_replace('/\b(' . $months . ')\b/u', ' ', $t);
        $t = preg_replace('/[0-9]+/u', ' ', $t);
        $t = preg_replace('/[^\p{L}\s&]+/u', ' ', $t);

        return trim(preg_replace('/\s+/u', ' ', $t));
    }

    /** The latest title with its month (and year) moved to $today's. */
    public static function rollTitle(string $title, CarbonImmutable $today): string
    {
        $replaced = false;
        $out = preg_replace_callback('/\b(' . implode('|', self::MONTHS) . '|jan|feb|mar|apr|jun|jul|aug|sept|sep|oct|nov|dec)\b/iu',
            function ($m) use ($today, &$replaced) {
                if ($replaced) {
                    return $m[0];
                }
                $replaced = true;
                $word = mb_strlen($m[0]) > 4 || in_array(mb_strtolower($m[0]), ['may', 'june', 'july'], true)
                    ? $today->format('F') : $today->format('M');
                if ($m[0] === mb_strtoupper($m[0]) && mb_strlen($m[0]) > 1) {
                    return mb_strtoupper($word);
                }

                return ctype_lower(mb_substr($m[0], 0, 1)) ? mb_strtolower($word) : $word;
            }, $title, 1);

        if ($replaced) {
            $out = preg_replace('/\b(19|20)\d{2}\b/', $today->format('Y'), $out, 1);
        }

        return $out;
    }

    /**
     * @param Builder $scoped the caller's own scoped Expense query
     */
    public function build(Builder $scoped, ?CarbonImmutable $today = null): array
    {
        $today = $today ?? CarbonImmutable::now(config('app.timezone'))->startOfDay();

        $rows = (clone $scoped)
            ->with(['category:id,name', 'outlet:id,name'])
            ->whereNotIn('status', ['rejected', 'cancelled'])
            ->where('expense_date', '>=', $today->subDays(self::WINDOW_DAYS)->toDateString())
            ->where('expense_date', '<=', $today->toDateString())
            ->orderBy('expense_date')->orderBy('id')
            ->get(['id', 'reference_number', 'title', 'category_id', 'amount', 'currency_code', 'expense_date',
                   'payment_method', 'payment_reference', 'vendor_name', 'outlet_id', 'department', 'status']);

        return [
            'as_of'       => $today->toDateString(),
            'window_days' => self::WINDOW_DAYS,
            'based_on'    => $rows->count(),
            'usuals'      => $this->usuals($rows, $today),
            'by_category' => $this->byCategory($rows),
            'by_vendor'   => $this->byVendor($rows),
            'vendors'     => $this->vendors($rows),
            'recent'      => $this->recent($rows, $today),
        ];
    }

    /** Expenses that come back: the same kind of thing, at least twice. */
    private function usuals(Collection $rows, CarbonImmutable $today): array
    {
        $groups = $rows->groupBy(fn ($e) => self::normalise($e->title) . '|' . $e->category_id)
            ->filter(fn ($g, $key) => $g->count() >= 2 && !str_starts_with($key, '|'));

        $out = $groups->map(function (Collection $g, string $key) use ($today) {
            $latest   = $g->last();
            $currency = self::mode($g->pluck('currency_code'));
            $amounts  = $g->where('currency_code', $currency)->pluck('amount')->map(fn ($a) => (float) $a)->values();
            $recent   = $amounts->slice(-6)->values();
            $dates    = $g->map(fn ($e) => CarbonImmutable::parse($e->expense_date))->values();

            [$cadence, $gap] = self::cadence($dates);
            $last    = $dates->last();
            $nextDue = $gap ? $last->addDays($gap) : null;

            return [
                'key'              => $key,
                'norm'             => self::normalise($latest->title),
                'title'            => $latest->title,
                'title_suggestion' => self::rollTitle($latest->title, $today),
                'category_id'      => $latest->category_id,
                'category_name'    => $latest->category?->name,
                'vendor_name'      => self::mode($g->pluck('vendor_name')),
                'payment_method'   => self::mode($g->pluck('payment_method')),
                'outlet_id'        => self::mode($g->pluck('outlet_id')),
                'outlet_name'      => $g->firstWhere('outlet_id', self::mode($g->pluck('outlet_id')))?->outlet?->name,
                'department'       => self::mode($g->pluck('department')),
                'currency_code'    => $currency,
                'count'            => $g->count(),
                'last_amount'      => $amounts->last(),
                'median_amount'    => self::median($amounts),
                'low'              => $recent->min(),
                'high'             => $recent->max(),
                'last_date'        => $last->toDateString(),
                'cadence'          => $cadence,
                'next_due'         => $nextDue?->toDateString(),
                'due_state'        => self::dueState($cadence, $last, $nextDue, $today),
            ];
        })->values();

        $rank = ['due' => 0, 'soon' => 1, null => 2, 'done' => 3];

        return $out->sort(function ($a, $b) use ($rank) {
            return [$rank[$a['due_state']] ?? 2, -$a['count'], $b['last_date']]
               <=> [$rank[$b['due_state']] ?? 2, -$b['count'], $a['last_date']];
        })->take(self::MAX_USUALS)->values()->all();
    }

    /** Weekly / monthly / quarterly when the gaps say so (three or more dates), else none. */
    private static function cadence(Collection $dates): array
    {
        if ($dates->count() < 3) {
            return [null, null];
        }
        $gaps = [];
        for ($i = 1; $i < $dates->count(); $i++) {
            $gaps[] = $dates[$i - 1]->diffInDays($dates[$i]);
        }
        $gap = (int) round(self::median(collect($gaps)));

        return match (true) {
            $gap >= 5 && $gap <= 9    => ['weekly', 7],
            $gap >= 25 && $gap <= 35  => ['monthly', $gap],
            $gap >= 80 && $gap <= 100 => ['quarterly', $gap],
            default                   => [null, null],
        };
    }

    /** done: already recorded this period · due: on or past its usual day · soon: within 5 days. */
    private static function dueState(?string $cadence, CarbonImmutable $last, ?CarbonImmutable $nextDue, CarbonImmutable $today): ?string
    {
        if (!$cadence || !$nextDue) {
            return null;
        }
        $samePeriod = match ($cadence) {
            'weekly'    => $last->isSameWeek($today),
            'monthly'   => $last->isSameMonth($today),
            'quarterly' => $last->isSameQuarter($today),
        };
        if ($samePeriod) {
            return 'done';
        }

        return match (true) {
            $today->greaterThanOrEqualTo($nextDue)            => 'due',
            $today->diffInDays($nextDue, false) <= 5          => 'soon',
            default                                           => null,
        };
    }

    /** Picking a category suggests what usually goes with it. */
    private function byCategory(Collection $rows): array
    {
        return $rows->groupBy('category_id')->map(fn (Collection $g) => [
            'vendor_name'    => self::mode($g->pluck('vendor_name'), 2),
            'payment_method' => self::mode($g->pluck('payment_method'), 2),
            'outlet_id'      => self::mode($g->pluck('outlet_id'), 2),
            'department'     => self::mode($g->pluck('department'), 2),
        ])->all();
    }

    /** Typing a known vendor suggests its usual category and payment method. */
    private function byVendor(Collection $rows): array
    {
        return $rows->filter(fn ($e) => trim((string) $e->vendor_name) !== '')
            ->groupBy(fn ($e) => mb_strtolower(trim($e->vendor_name)))
            ->map(fn (Collection $g) => [
                'vendor_name'    => $g->last()->vendor_name,
                'category_id'    => self::mode($g->pluck('category_id')),
                'payment_method' => self::mode($g->pluck('payment_method')),
            ])->all();
    }

    /** Vendors by how often they are used, for the vendor field's list. */
    private function vendors(Collection $rows): array
    {
        return $rows->filter(fn ($e) => trim((string) $e->vendor_name) !== '')
            ->groupBy(fn ($e) => mb_strtolower(trim($e->vendor_name)))
            ->sortByDesc(fn ($g) => $g->count())
            ->map(fn ($g) => $g->last()->vendor_name)
            ->take(30)->values()->all();
    }

    /** The last weeks' expenses, for "this looks like one already recorded". */
    private function recent(Collection $rows, CarbonImmutable $today): array
    {
        $since = $today->subDays(self::RECENT_DAYS)->toDateString();

        return $rows->filter(fn ($e) => CarbonImmutable::parse($e->expense_date)->toDateString() >= $since)
            ->map(fn ($e) => [
                'id'                => $e->id,
                'reference_number'  => $e->reference_number,
                'title'             => $e->title,
                'norm'              => self::normalise($e->title),
                'vendor_name'       => $e->vendor_name,
                'amount'            => (float) $e->amount,
                'currency_code'     => $e->currency_code,
                'expense_date'      => CarbonImmutable::parse($e->expense_date)->toDateString(),
                'payment_reference' => $e->payment_reference,
            ])->values()->all();
    }

    /** The most common non-empty value, if it appears at least $min times. */
    private static function mode(Collection $values, int $min = 1): mixed
    {
        $counts = $values->filter(fn ($v) => $v !== null && $v !== '')->countBy(fn ($v) => (string) $v);
        if ($counts->isEmpty()) {
            return null;
        }
        $top = $counts->sortDesc()->keys()->first();
        if ($counts[$top] < $min) {
            return null;
        }
        $original = $values->first(fn ($v) => (string) $v === $top);

        return is_numeric($original) && !is_string($original) ? $original : ($original ?? $top);
    }

    private static function median(Collection $values): ?float
    {
        $v = $values->sort()->values();
        $n = $v->count();
        if ($n === 0) {
            return null;
        }

        return $n % 2 ? (float) $v[intdiv($n, 2)] : ((float) $v[$n / 2 - 1] + (float) $v[$n / 2]) / 2;
    }
}

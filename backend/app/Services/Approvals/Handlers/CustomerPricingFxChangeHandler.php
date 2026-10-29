<?php

namespace App\Services\Approvals\Handlers;

use App\Models\ChangeProposal;
use App\Models\User;
use App\Services\CurrencyPricing;
use App\Support\ReportingCurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * customer_pricing_fx_change — currencies.exchange_rate, the base-relative
 * rate a customer is QUOTED at (App\Services\CurrencyPricing; not the reporting
 * rate) — and currencies.priced_from, the price list those customer prices
 * are converted from (null = shillings; the rand from USD, owner 2026-10-29).
 * Both decide what a customer pays, so both are proposed and signed. Admin
 * proposes (settings.pricing_rate_propose); finance signs every one. Applies
 * when signed. The base currency's rate cannot change.
 */
final class CustomerPricingFxChangeHandler extends ProposalHandler
{
    public function event(): string { return 'customer_pricing_fx_change'; }
    public function subjectType(): string { return 'currency'; }
    public function title(): string { return 'Customer pricing rate'; }
    public function valued(): bool { return false; }
    public function makerPermissions(): array { return ['settings.pricing_rate_propose', 'settings.edit']; }

    public function fields(): array
    {
        return ['exchange_rate' => 'Pricing rate (per 1 base)', 'priced_from' => 'Prices converted from'];
    }

    public function current(int $subjectId): ?array
    {
        $c = DB::table('currencies')->where('id', $subjectId)->first();

        return $c ? ['exchange_rate' => (float) $c->exchange_rate, 'priced_from' => $c->priced_from ?? null] : null;
    }

    public function label(int $subjectId): string
    {
        $c = DB::table('currencies')->where('id', $subjectId)->first();

        return $c ? "{$c->code} — {$c->name}" : "Currency #{$subjectId}";
    }

    public function normalise(array $new, int $subjectId): array
    {
        $out = array_intersect_key($new, $this->fields());
        $c = DB::table('currencies')->where('id', $subjectId)->first();
        if ($c && ($c->is_base || $c->is_default) && array_key_exists('exchange_rate', $out)) {
            throw ValidationException::withMessages(['exchange_rate' => 'Cannot change the exchange rate of the base currency.']);
        }
        if (array_key_exists('exchange_rate', $out)) {
            if (!is_numeric($out['exchange_rate']) || (float) $out['exchange_rate'] <= 0) {
                throw ValidationException::withMessages(['exchange_rate' => 'A pricing rate is more than zero.']);
            }
            $out['exchange_rate'] = round((float) $out['exchange_rate'], 6);
        }
        if (array_key_exists('priced_from', $out)) {
            $from = strtoupper(trim((string) $out['priced_from']));
            $base = strtoupper((string) (DB::table('currencies')->where('is_base', true)->value('code') ?: 'KES'));
            if ($from === '' || $from === $base) {
                $out['priced_from'] = null;                       // the shop's own prices — the default
            } else {
                $row = DB::table('currencies')->whereRaw('UPPER(code) = ?', [$from])->first();
                if (!$row || !$row->is_active || ($c && strtoupper((string) $c->code) === $from) || !empty($row->priced_from)) {
                    throw ValidationException::withMessages(['priced_from' => "Prices can be converted from the shillings price, or from another active currency that is itself priced from shillings — not from {$from}."]);
                }
                $out['priced_from'] = $from;
            }
        }

        return $out;
    }

    public function measure(ChangeProposal $p): array
    {
        return ['direct_basis' => null, 'direct_ok' => false, 'band_basis' => null];
    }

    public function apply(ChangeProposal $p, array $new, ?User $by): void
    {
        $set = array_intersect_key($new, ['exchange_rate' => 1, 'priced_from' => 1]);
        if ($set === []) {
            return;
        }
        DB::table('currencies')->where('id', $p->subject_id)->update($set + ['updated_at' => now()]);
        CurrencyPricing::forget();
        ReportingCurrency::forget();
    }

    public function link(ChangeProposal $p): ?string
    {
        return '/settings/currencies';
    }

    public function formatValue(string $field, mixed $value, ChangeProposal $p): string
    {
        if ($field === 'priced_from') {
            return $value ? "the {$value} price" : 'the shillings price';
        }

        return $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 6), '0'), '.');
    }
}

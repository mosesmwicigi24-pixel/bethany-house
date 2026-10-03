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
 * rate). Admin proposes (settings.pricing_rate_propose); finance signs every
 * one. Applies when signed. The base currency's rate cannot change.
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
        return ['exchange_rate' => 'Pricing rate (per 1 base)'];
    }

    public function current(int $subjectId): ?array
    {
        $c = DB::table('currencies')->where('id', $subjectId)->first();

        return $c ? ['exchange_rate' => (float) $c->exchange_rate] : null;
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

        return $out;
    }

    public function measure(ChangeProposal $p): array
    {
        return ['direct_basis' => null, 'direct_ok' => false, 'band_basis' => null];
    }

    public function apply(ChangeProposal $p, array $new, ?User $by): void
    {
        if (!array_key_exists('exchange_rate', $new)) {
            return;
        }
        DB::table('currencies')->where('id', $p->subject_id)
            ->update(['exchange_rate' => $new['exchange_rate'], 'updated_at' => now()]);
        CurrencyPricing::forget();
        ReportingCurrency::forget();
    }

    public function link(ChangeProposal $p): ?string
    {
        return '/settings/currencies';
    }

    public function formatValue(string $field, mixed $value, ChangeProposal $p): string
    {
        return $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 6), '0'), '.');
    }
}

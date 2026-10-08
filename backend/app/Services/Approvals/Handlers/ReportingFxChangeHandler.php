<?php

namespace App\Services\Approvals\Handlers;

use App\Models\ChangeProposal;
use App\Models\User;
use App\Services\CurrencyPricing;
use App\Support\ReportingCurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * reporting_fx_change — currencies.reporting_rate_to_kes, what a unit of the
 * currency already earned is worth in KES (App\Support\ReportingCurrency; NOT
 * the pricing rate). Finance proposes; the super admin signs every one.
 * Effective-dated, never retroactive as a CHANGE: the new rate is written at
 * effective_from (or on signing if later), and the change_proposals rows keep
 * every rate with when it took effect.
 *
 * Note for the reports: ReportingCurrency states every sale at the CURRENT
 * rate, past periods included. That is today's reporting basis and 3C does not
 * change it; the history kept here is what a dated-rate basis would read.
 */
final class ReportingFxChangeHandler extends ProposalHandler
{
    public function event(): string { return 'reporting_fx_change'; }
    public function subjectType(): string { return 'currency'; }
    public function title(): string { return 'Reporting exchange rate'; }
    public function effectiveDated(): bool { return true; }
    public function valued(): bool { return false; }
    public function makerPermissions(): array { return ['settings.financial_propose', 'settings.edit']; }

    public function fields(): array
    {
        return ['reporting_rate_to_kes' => 'KES per unit (reporting)'];
    }

    public function current(int $subjectId): ?array
    {
        $c = DB::table('currencies')->where('id', $subjectId)->first();

        return $c ? ['reporting_rate_to_kes' => $c->reporting_rate_to_kes === null ? null : (float) $c->reporting_rate_to_kes] : null;
    }

    public function label(int $subjectId): string
    {
        $c = DB::table('currencies')->where('id', $subjectId)->first();

        return $c ? "{$c->code} — {$c->name}" : "Currency #{$subjectId}";
    }

    public function normalise(array $new, int $subjectId): array
    {
        $out = array_intersect_key($new, $this->fields());
        $code = strtoupper((string) DB::table('currencies')->where('id', $subjectId)->value('code'));
        if ($code === 'KES' && array_key_exists('reporting_rate_to_kes', $out)) {
            throw ValidationException::withMessages(['reporting_rate_to_kes' => 'KES is the reporting currency; its rate is always 1.']);
        }
        if (array_key_exists('reporting_rate_to_kes', $out)) {
            $r = $out['reporting_rate_to_kes'];
            if ($r !== null && (!is_numeric($r) || (float) $r <= 0)) {
                throw ValidationException::withMessages(['reporting_rate_to_kes' => 'A reporting rate is more than zero, or empty for "do not convert".']);
            }
            $out['reporting_rate_to_kes'] = $r === null ? null : round((float) $r, 6);
        }

        return $out;
    }

    public function measure(ChangeProposal $p): array
    {
        return ['direct_basis' => null, 'direct_ok' => false, 'band_basis' => null];
    }

    public function apply(ChangeProposal $p, array $new, ?User $by): void
    {
        if (!array_key_exists('reporting_rate_to_kes', $new)) {
            return;
        }
        DB::table('currencies')->where('id', $p->subject_id)
            ->update(['reporting_rate_to_kes' => $new['reporting_rate_to_kes'], 'updated_at' => now()]);
        ReportingCurrency::forget();
        CurrencyPricing::forget();
    }

    public function link(ChangeProposal $p): ?string
    {
        return '/settings/currencies';
    }

    public function formatValue(string $field, mixed $value, ChangeProposal $p): string
    {
        return $value === null ? 'not set (do not convert)' : rtrim(rtrim(number_format((float) $value, 6), '0'), '.');
    }
}

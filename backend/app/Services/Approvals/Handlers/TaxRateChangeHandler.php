<?php

namespace App\Services\Approvals\Handlers;

use App\Models\ChangeProposal;
use App\Models\User;
use App\Services\TaxCalculationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * tax_rate_change — what a tax rate charges: its rate, whether it is active,
 * and whether it is the default (the three fields TaxCalculationService reads).
 * Finance proposes (settings.financial_propose); the super admin signs every
 * one. Effective-dated: it takes effect at effective_from (never in the past),
 * or when signed if that is later — never retroactively. Orders keep the tax
 * computed on them at sale; the change_proposals rows are the rate's history.
 */
final class TaxRateChangeHandler extends ProposalHandler
{
    public function event(): string { return 'tax_rate_change'; }
    public function subjectType(): string { return 'tax_rate'; }
    public function title(): string { return 'Tax rate'; }
    public function effectiveDated(): bool { return true; }
    public function valued(): bool { return false; }
    public function makerPermissions(): array { return ['settings.financial_propose', 'settings.edit']; }

    public function fields(): array
    {
        return ['rate' => 'Rate (%)', 'is_active' => 'Active', 'is_default' => 'Default rate'];
    }

    public function current(int $subjectId): ?array
    {
        $r = DB::table('tax_rates')->where('id', $subjectId)->first();

        return $r ? [
            'rate'       => (float) $r->rate,
            'is_active'  => (bool) $r->is_active,
            'is_default' => (bool) ($r->is_default ?? false),
        ] : null;
    }

    public function label(int $subjectId): string
    {
        $r = DB::table('tax_rates')->where('id', $subjectId)->first();

        return $r ? trim($r->name . (($r->code ?? null) ? " ({$r->code})" : '')) : "Tax rate #{$subjectId}";
    }

    public function normalise(array $new, int $subjectId): array
    {
        $out = array_intersect_key($new, $this->fields());
        if (array_key_exists('rate', $out)) {
            if (!is_numeric($out['rate']) || (float) $out['rate'] < 0 || (float) $out['rate'] > 100) {
                throw ValidationException::withMessages(['rate' => 'A tax rate is a percentage from 0 to 100.']);
            }
            $out['rate'] = round((float) $out['rate'], 4);
        }
        foreach (['is_active', 'is_default'] as $f) {
            if (array_key_exists($f, $out)) {
                $out[$f] = filter_var($out[$f], FILTER_VALIDATE_BOOLEAN);
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
        $update = array_intersect_key($new, $this->fields());
        if ($update === []) {
            return;
        }
        if (!empty($update['is_default'])) {
            DB::table('tax_rates')->where('id', '!=', $p->subject_id)->update(['is_default' => false, 'updated_at' => now()]);
        }
        DB::table('tax_rates')->where('id', $p->subject_id)->update($update + ['updated_at' => now()]);

        TaxCalculationService::invalidateGlobalCache();
        // Per-product rate lists are cached too; a changed rate must reach the till at once.
        DB::table('product_tax_rates')->where('tax_rate_id', $p->subject_id)->pluck('product_id')
            ->each(fn ($id) => Cache::forget("tax_rates_product_{$id}"));
    }

    public function link(ChangeProposal $p): ?string
    {
        return '/settings/taxes';
    }

    public function formatValue(string $field, mixed $value, ChangeProposal $p): string
    {
        if ($field === 'rate') {
            return $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 4), '0'), '.') . '%';
        }

        return parent::formatValue($field, $value, $p);
    }
}

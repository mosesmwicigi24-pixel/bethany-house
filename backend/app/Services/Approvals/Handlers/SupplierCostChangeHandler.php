<?php

namespace App\Services\Approvals\Handlers;

use App\Models\ChangeProposal;
use App\Models\Material;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * supplier_cost_change — what we pay a supplier for a raw material
 * (materials.unit_cost: the figure BOM costing reads). The hub has no separate
 * supplier price list; this is the supplier pricing it keeps.
 *
 *   change ≤ 5 % (against the cost 24 h ago) → applies at once, for whoever may
 *                                              edit materials (inventory.adjust)
 *   ≤ 25 %                                   → + finance
 *   above                                    → + super admin
 *
 * A first cost measures 0, clearing one 100 % (ProposalHandler::changePct).
 * Material transactions keep the unit cost they were recorded at.
 */
final class SupplierCostChangeHandler extends ProposalHandler
{
    public function event(): string { return 'supplier_cost_change'; }
    public function subjectType(): string { return 'material'; }
    public function title(): string { return 'Supplier cost'; }
    public function directEvent(): ?string { return 'supplier_cost_change_direct'; }
    public function unit(): string { return 'percent'; }
    public function makerPermissions(): array { return ['inventory.adjust', 'procurement.receive']; }

    public function fields(): array
    {
        return ['unit_cost' => 'Unit cost (KES)'];
    }

    public function current(int $subjectId): ?array
    {
        $m = Material::find($subjectId);

        return $m ? ['unit_cost' => $m->unit_cost === null ? null : (float) $m->unit_cost] : null;
    }

    public function label(int $subjectId): string
    {
        $m = Material::find($subjectId);

        return $m ? "{$m->name} ({$m->code}) per {$m->unit_of_measure}" : "Material #{$subjectId}";
    }

    public function normalise(array $new, int $subjectId): array
    {
        $out = array_intersect_key($new, $this->fields());
        if (array_key_exists('unit_cost', $out)) {
            $c = $out['unit_cost'];
            if ($c !== null && (!is_numeric($c) || (float) $c < 0)) {
                throw ValidationException::withMessages(['unit_cost' => 'The unit cost must be a number, zero or more.']);
            }
            $out['unit_cost'] = $c === null ? null : round((float) $c, 2);
        }

        return $out;
    }

    public function measure(ChangeProposal $p): array
    {
        $live = $this->current((int) $p->subject_id) ?? [];
        $pct  = self::changePct($this->baseline($p, 'unit_cost', $live['unit_cost'] ?? null), $p->changeset['unit_cost']['new'] ?? null);

        return ['direct_basis' => $pct, 'direct_ok' => true, 'band_basis' => $pct, 'change_pct' => $pct];
    }

    public function apply(ChangeProposal $p, array $new, ?User $by): void
    {
        $m = Material::whereKey($p->subject_id)->lockForUpdate()->firstOrFail();
        if (array_key_exists('unit_cost', $new)) {
            $m->update(['unit_cost' => $new['unit_cost']]);
        }
    }

    public function link(ChangeProposal $p): ?string
    {
        return '/inventory/materials';
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

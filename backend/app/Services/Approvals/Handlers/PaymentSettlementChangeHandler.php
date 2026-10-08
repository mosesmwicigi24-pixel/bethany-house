<?php

namespace App\Services\Approvals\Handlers;

use App\Models\ChangeProposal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * payment_settlement_change — whether a payment taken by a method counts at
 * once or waits for review (payment_methods.requires_approval, read by every
 * payment-recording path through PaymentMethod::deriveRequiresApproval).
 * Turning review OFF makes money count as received without a second look, so
 * finance proposes and the super admin signs every one. Effective-dated, never
 * retroactive: payments already recorded keep the status they were given.
 */
final class PaymentSettlementChangeHandler extends ProposalHandler
{
    public function event(): string { return 'payment_settlement_change'; }
    public function subjectType(): string { return 'payment_method'; }
    public function title(): string { return 'Payment settlement'; }
    public function effectiveDated(): bool { return true; }
    public function valued(): bool { return false; }
    public function makerPermissions(): array { return ['settings.financial_propose', 'settings.edit']; }

    public function fields(): array
    {
        return ['requires_approval' => 'Payments wait for review'];
    }

    public function current(int $subjectId): ?array
    {
        $m = DB::table('payment_methods')->where('id', $subjectId)->first();

        return $m ? ['requires_approval' => $m->requires_approval === null ? null : (bool) $m->requires_approval] : null;
    }

    public function label(int $subjectId): string
    {
        $m = DB::table('payment_methods')->where('id', $subjectId)->first();

        return $m ? "{$m->name} ({$m->code})" : "Payment method #{$subjectId}";
    }

    public function normalise(array $new, int $subjectId): array
    {
        $out = array_intersect_key($new, $this->fields());
        if (array_key_exists('requires_approval', $out)) {
            $out['requires_approval'] = filter_var($out['requires_approval'], FILTER_VALIDATE_BOOLEAN);
        }

        return $out;
    }

    public function measure(ChangeProposal $p): array
    {
        return ['direct_basis' => null, 'direct_ok' => false, 'band_basis' => null];
    }

    public function apply(ChangeProposal $p, array $new, ?User $by): void
    {
        if (!array_key_exists('requires_approval', $new)) {
            return;
        }
        DB::table('payment_methods')->where('id', $p->subject_id)
            ->update(['requires_approval' => (bool) $new['requires_approval'], 'updated_at' => now()]);
    }

    public function link(ChangeProposal $p): ?string
    {
        return '/settings/payment-methods';
    }
}

<?php

namespace App\Services\Approvals;

use App\Models\ChangeProposal;
use App\Models\User;

/** What an edit endpoint tells the editor about the proposals its save raised (Phase 3C). */
final class ProposalMessages
{
    /** @param list<ChangeProposal> $proposals */
    public static function saved(string $base, array $proposals): string
    {
        $service = app(ProposalService::class);
        $waiting = array_filter($proposals, fn (ChangeProposal $p) => $p->status !== ChangeProposal::APPLIED);
        if ($waiting === []) {
            return $base;
        }

        return rtrim($base, '.') . '. ' . implode(' ', array_map(fn ($p) => $service->message($p), $waiting));
    }

    /** @param list<ChangeProposal> $proposals */
    public static function present(array $proposals, ?User $viewer): array
    {
        $service = app(ProposalService::class);

        return array_map(fn (ChangeProposal $p) => $service->present($p->fresh('maker'), $viewer), $proposals);
    }
}

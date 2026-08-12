<?php

namespace App\Services\Production;

use App\Models\MovementReason;
use App\Models\PieceMovement;
use App\Models\ProductionWorkflowStage;
use Illuminate\Support\Collection;

/**
 * The shape of a line, and the rules about walking it.
 *
 * Pure: no database, no request, no clock. Hand it the stages of a workflow and
 * it will tell you what comes next and refuse anything that would put a piece
 * somewhere it cannot physically be. That purity is deliberate — this is where
 * "you cannot sew something that was never cut" lives, and it is the part most
 * worth testing exhaustively without a database in the way.
 */
class WorkflowGraph
{
    /** @var Collection<int, ProductionWorkflowStage> */
    private Collection $stages;

    /** @param iterable<ProductionWorkflowStage> $stages */
    public function __construct(iterable $stages)
    {
        $this->stages = collect($stages)->sortBy('seq')->values();
    }

    /** @return Collection<int, ProductionWorkflowStage> */
    public function all(): Collection
    {
        return $this->stages;
    }

    /**
     * The stages pieces actually travel through, in order. SCRAP is excluded —
     * it sits at seq 9999 off the main line, and including it would make it
     * look like the stage after Finished.
     *
     * @return Collection<int, ProductionWorkflowStage>
     */
    public function line(): Collection
    {
        return $this->stages
            ->filter(fn (ProductionWorkflowStage $s) => $s->kind !== ProductionWorkflowStage::KIND_SCRAP)
            ->sortBy('seq')
            ->values();
    }

    public function find(?int $stageId): ?ProductionWorkflowStage
    {
        if ($stageId === null) {
            return null;
        }

        return $this->stages->firstWhere('id', $stageId);
    }

    public function ofKind(string $kind): ?ProductionWorkflowStage
    {
        return $this->stages->firstWhere('kind', $kind);
    }

    public function queueStage(): ?ProductionWorkflowStage
    {
        return $this->ofKind(ProductionWorkflowStage::KIND_QUEUE);
    }

    public function doneStage(): ?ProductionWorkflowStage
    {
        return $this->ofKind(ProductionWorkflowStage::KIND_DONE);
    }

    public function scrapStage(): ?ProductionWorkflowStage
    {
        return $this->ofKind(ProductionWorkflowStage::KIND_SCRAP);
    }

    /** The station immediately downstream, or null at the end of the line. */
    public function nextStage(int $fromStageId): ?ProductionWorkflowStage
    {
        $line = $this->line();
        $i    = $line->search(fn (ProductionWorkflowStage $s) => $s->id === $fromStageId);

        if ($i === false || $i === $line->count() - 1) {
            return null;
        }

        return $line->get($i + 1);
    }

    /**
     * Stages a rework may legitimately target: earlier, on the line, and not the
     * uncut queue. Used to populate the supervisor's picker so the UI cannot
     * offer a destination the engine would then refuse.
     *
     * @return Collection<int, ProductionWorkflowStage>
     */
    public function reworkTargets(ProductionWorkflowStage $from): Collection
    {
        if (!$from->allows_rework) {
            return collect();
        }

        return $this->line()
            ->filter(fn (ProductionWorkflowStage $s) => $s->seq < $from->seq
                && $s->kind !== ProductionWorkflowStage::KIND_QUEUE)
            ->values();
    }

    /**
     * Validate a proposed transition, or throw.
     *
     * @throws MovementException
     */
    /**
     * Can pieces leave this stage at all?
     *
     * Checked before a destination is resolved, deliberately. Asking for the
     * stage after Finished yields nothing, so resolution-first would report
     * "no next stage" — technically true and useless to the worker holding the
     * garment. "Finished is the end of the line" is the answer they need, and
     * it is what the operating spec's edge-case table calls for.
     */
    public function assertOriginMovable(string $type, ProductionWorkflowStage $from): void
    {
        // Scrapped pieces are gone. They do not come back to be re-sewn, and
        // letting them would quietly re-inflate a batch that was written down.
        if ($from->kind === ProductionWorkflowStage::KIND_SCRAP) {
            throw new MovementException(
                MovementException::TERMINAL_STAGE,
                'Scrapped pieces cannot re-enter production.',
            );
        }

        if ($from->kind === ProductionWorkflowStage::KIND_DONE
            && $type === PieceMovement::TYPE_FORWARD) {
            throw new MovementException(
                MovementException::TERMINAL_STAGE,
                "{$from->name} is the end of the line.",
            );
        }
    }

    public function assertTransition(
        string $type,
        ProductionWorkflowStage $from,
        ProductionWorkflowStage $to,
        ?string $reasonCode = null,
    ): void {
        $this->assertOriginMovable($type, $from);

        match ($type) {
            PieceMovement::TYPE_FORWARD => $this->assertForward($from, $to),
            PieceMovement::TYPE_REWORK  => $this->assertRework($from, $to, $reasonCode),
            PieceMovement::TYPE_SCRAP   => $this->assertScrap($to, $reasonCode),
            PieceMovement::TYPE_HOLD,
            PieceMovement::TYPE_RELEASE => $this->assertHoldRelease($type, $from, $to, $reasonCode),
            default => throw new MovementException(
                MovementException::INVALID_TRANSITION,
                "{$type} is not a movement the line understands.",
            ),
        };

        $this->assertReasonFits($type, $reasonCode);
    }

    private function assertForward(ProductionWorkflowStage $from, ProductionWorkflowStage $to): void
    {
        $next = $this->nextStage($from->id);

        if (!$next) {
            throw new MovementException(
                MovementException::NO_NEXT_STAGE,
                "{$from->name} has no next stage.",
            );
        }

        // Stages cannot be skipped. If a garment genuinely bypasses Button
        // Fixing that is a different workflow, not a skipped stage — and
        // workflows are per-product data, so create one.
        if ($next->id !== $to->id) {
            throw new MovementException(
                MovementException::INVALID_TRANSITION,
                "Pieces move {$from->name} → {$next->name}. Stages cannot be skipped.",
                ['expected' => $next->code, 'got' => $to->code],
            );
        }
    }

    private function assertRework(
        ProductionWorkflowStage $from,
        ProductionWorkflowStage $to,
        ?string $reasonCode,
    ): void {
        if (!$from->allows_rework) {
            throw new MovementException(
                MovementException::INVALID_TRANSITION,
                "{$from->name} cannot send work backwards.",
            );
        }

        if ($to->seq >= $from->seq) {
            throw new MovementException(
                MovementException::INVALID_TRANSITION,
                'Rework must target an earlier stage.',
            );
        }

        // Returning pieces to the uncut queue would claim they are raw fabric
        // again, which double-counts the cut. Scrap is the honest answer.
        if ($to->kind === ProductionWorkflowStage::KIND_QUEUE) {
            throw new MovementException(
                MovementException::INVALID_TRANSITION,
                'Rework cannot return pieces to the uncut queue. Scrap them instead.',
            );
        }

        if ($to->kind === ProductionWorkflowStage::KIND_SCRAP) {
            throw new MovementException(
                MovementException::INVALID_TRANSITION,
                'Use a scrap movement to write pieces off.',
            );
        }

        if (!$reasonCode) {
            throw new MovementException(
                MovementException::REASON_REQUIRED,
                'Rework requires a reason.',
            );
        }
    }

    private function assertScrap(ProductionWorkflowStage $to, ?string $reasonCode): void
    {
        $scrap = $this->scrapStage();

        if (!$scrap || $scrap->id !== $to->id) {
            throw new MovementException(
                MovementException::INVALID_TRANSITION,
                'Scrap must target the scrap stage.',
            );
        }

        if (!$reasonCode) {
            throw new MovementException(
                MovementException::REASON_REQUIRED,
                'Scrap requires a reason.',
            );
        }
    }

    private function assertHoldRelease(
        string $type,
        ProductionWorkflowStage $from,
        ProductionWorkflowStage $to,
        ?string $reasonCode,
    ): void {
        // A hold never moves the garment. It only changes whether it can be
        // worked — a cassock waiting for buttons is still at Button Fixing.
        if ($from->id !== $to->id) {
            throw new MovementException(
                MovementException::INVALID_TRANSITION,
                'Hold and release do not move pieces.',
            );
        }

        if ($type === PieceMovement::TYPE_HOLD && !$reasonCode) {
            throw new MovementException(
                MovementException::REASON_REQUIRED,
                'A hold requires a reason.',
            );
        }
    }

    /**
     * A reason must explain the movement it is attached to. Without this a
     * scrap could be filed under "waiting on buttons", and the defect Pareto —
     * the entire reason reasons are mandatory — would quietly fill with noise.
     */
    private function assertReasonFits(string $type, ?string $reasonCode): void
    {
        if ($reasonCode === null) {
            return;
        }

        $reason = MovementReason::find($reasonCode);

        if (!$reason || !$reason->is_active) {
            throw new MovementException(
                MovementException::REASON_INVALID,
                "'{$reasonCode}' is not a reason this line recognises.",
                ['reason_code' => $reasonCode],
            );
        }

        if ($reason->applies_to !== $type) {
            throw new MovementException(
                MovementException::REASON_INVALID,
                "'{$reason->label}' explains a ".strtolower($reason->applies_to)
                    .' movement, not a '.strtolower($type).' one.',
                ['reason_code' => $reasonCode, 'applies_to' => $reason->applies_to],
            );
        }
    }
}

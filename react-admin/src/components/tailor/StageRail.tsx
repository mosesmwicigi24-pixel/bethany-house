/**
 * StageRail — the signature element of the tailor screen.
 *
 * Instead of an abstract "6 / 20", a tailor sees the physical line: every piece
 * of this batch, where it is standing right now, with her own bench raised out
 * of the strip. That answers "where is my work inside the whole job", which is
 * the question a counter could never answer and the reason the ledger exists.
 *
 * Three rules this component exists to keep:
 *
 * 1. **Colour is never the only signal.** Every segment carries a number and a
 *    label, and the tailor's own station is raised, outlined and marked. A
 *    screen read in workshop light by someone who may not read English still
 *    resolves.
 * 2. **Held pieces stay in their real stage.** They are hatched where they
 *    physically are, not moved to some "on hold" column — a cassock waiting for
 *    buttons is at Button fixing, and saying otherwise breaks the one question
 *    this screen answers.
 * 3. **Empty stages still occupy space.** A line that reflows every time work
 *    moves cannot be read at a glance; segments have a floor width so the shape
 *    of the line stays learnable.
 */

import clsx from 'clsx'
import type { StationStage } from '../../hooks/useStationQueue'

interface Props {
  workflow: StationStage[]
  distribution: Record<string, number>
  held?: Record<string, number>
  myStageCode: string
  compact?: boolean
  /** Scrap only earns a column when it is non-zero — visible when it matters. */
  showScrap?: boolean
}

/** Fill per stage kind. The tailor's own bench always wins. */
function fillFor(stage: StationStage, isMine: boolean, qty: number): string {
  if (isMine) return 'bg-brand-500'
  if (qty === 0) return 'bg-surface-200'

  switch (stage.kind) {
    case 'DONE':
      return 'bg-success'
    case 'SCRAP':
      return 'bg-danger'
    case 'INSPECT':
      return 'bg-warning'
    case 'QUEUE':
      return 'bg-surface-400'
    default:
      return 'bg-surface-500'
  }
}

export default function StageRail({
  workflow,
  distribution,
  held = {},
  myStageCode,
  compact = false,
  showScrap = true,
}: Props) {
  const stages = workflow
    .filter((s) => s.kind !== 'SCRAP' || (showScrap && (distribution[s.code] ?? 0) > 0))
    .sort((a, b) => a.seq - b.seq)

  const total = stages.reduce((sum, s) => sum + (distribution[s.code] ?? 0), 0) || 1

  return (
    <div>
      <div className={clsx('flex items-end gap-[3px]', compact ? 'h-[34px]' : 'h-11')}>
        {stages.map((stage) => {
          const qty = distribution[stage.code] ?? 0
          const stopped = held[stage.code] ?? 0
          const isMine = stage.code === myStageCode

          // Proportional, but never so thin it cannot hold its own number.
          const grow = Math.max(qty / total, qty > 0 ? 0.055 : 0.028)

          return (
            <div key={stage.code} className="min-w-[20px] basis-0" style={{ flexGrow: grow }}>
              <div
                className={clsx(
                  'relative flex items-center justify-center overflow-hidden rounded',
                  fillFor(stage, isMine, qty),
                  isMine
                    ? clsx('ring-2 ring-surface-900', compact ? 'h-[26px]' : 'h-[34px]')
                    : compact
                      ? 'h-4'
                      : 'h-5',
                )}
                title={`${stage.name}: ${qty}${stopped ? ` (${stopped} on hold)` : ''}`}
              >
                {stopped > 0 && (
                  <span
                    aria-hidden
                    className="absolute inset-0 opacity-80"
                    style={{
                      backgroundImage:
                        'repeating-linear-gradient(45deg, #dc2626 0 3px, transparent 3px 7px)',
                    }}
                  />
                )}
                {qty > 0 && (
                  <span
                    className={clsx(
                      'relative font-extrabold tabular-nums text-white',
                      isMine ? 'text-sm' : 'text-[11px]',
                    )}
                  >
                    {qty}
                  </span>
                )}
              </div>
            </div>
          )
        })}
      </div>

      {!compact && (
        <div className="mt-1.5 flex gap-[3px]">
          {stages.map((stage) => {
            const qty = distribution[stage.code] ?? 0
            const isMine = stage.code === myStageCode
            const grow = Math.max(qty / total, qty > 0 ? 0.055 : 0.028)

            return (
              <div
                key={stage.code}
                className={clsx(
                  'min-w-[20px] basis-0 truncate text-center text-[9px] uppercase leading-tight tracking-wide',
                  isMine ? 'font-extrabold text-surface-900' : 'font-medium text-surface-500',
                )}
                style={{ flexGrow: grow }}
              >
                {stage.name}
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}

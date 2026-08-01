/**
 * MoveSheet — how many, and where to.
 *
 * The screen's one decision. Everything here exists to make a wrong number
 * impossible rather than to catch it afterwards:
 *
 * - A chip above what the bench holds is rendered disabled, not enabled and
 *   then rejected. A live "10" on a stage holding 6 is how impossible totals
 *   get typed in.
 * - "Other" clamps silently as you type. Someone entering 250 sees 6, with the
 *   limit spelled out beside it — no error state, nothing to dismiss.
 * - The preview shows both sides of the transfer before the tap commits, so
 *   what is about to happen is legible without arithmetic.
 * - The verb on the button is the verb in the confirmation: "Move to Button
 *   fixing · 4" is answered by "4 moved to Button fixing".
 *
 * Targets are deliberately large: 52px chips, a 56px primary. This is used
 * standing up, by someone whose hands have been on a machine all morning.
 */

import { useEffect, useState } from 'react'
import clsx from 'clsx'
import { clampMove, moveChips } from '../../lib/productionSelectors'
import type { Copy } from '../../lib/tailorCopy'

interface Props {
  available: number
  fromStageName: string
  toStageName: string | null
  /** Pieces currently at the destination, for the preview. */
  toStageQty: number
  disabled?: boolean
  t: Copy
  onMove: (quantity: number) => void
}

export default function MoveSheet({
  available,
  fromStageName,
  toStageName,
  toStageQty,
  disabled = false,
  t,
  onMove,
}: Props) {
  const [qty, setQty] = useState(0)
  const [customOpen, setCustomOpen] = useState(false)
  const [customValue, setCustomValue] = useState('')

  // Any refresh of the queue clears an armed quantity. Someone else may have
  // taken those pieces while the sheet sat open, and a stale number must never
  // be sendable.
  useEffect(() => {
    if (qty > available) {
      setQty(0)
      setCustomValue('')
    }
  }, [available, qty])

  const { chips, allChip } = moveChips(available, [1, 5, 10], disabled || !toStageName)
  const canMove = qty > 0 && !disabled && Boolean(toStageName)

  return (
    <div>
      <p className="mt-3.5 text-xs font-bold text-surface-600">{t('move.question')}</p>

      <div className="mt-2 flex flex-wrap gap-2">
        {chips.map((chip) => (
          <button
            key={chip.value}
            type="button"
            disabled={!chip.enabled}
            aria-pressed={qty === chip.value}
            onClick={() => {
              setQty(chip.value)
              setCustomOpen(false)
            }}
            className={chipClass(qty === chip.value, !chip.enabled)}
          >
            {chip.label}
          </button>
        ))}

        {allChip && (
          <button
            type="button"
            aria-pressed={qty === allChip.value}
            onClick={() => {
              setQty(allChip.value)
              setCustomOpen(false)
            }}
            className={chipClass(qty === allChip.value, false)}
          >
            {t('move.all', { n: allChip.value })}
          </button>
        )}

        <button
          type="button"
          disabled={disabled || available === 0}
          aria-expanded={customOpen}
          onClick={() => setCustomOpen((open) => !open)}
          className={chipClass(customOpen, disabled || available === 0)}
        >
          {t('move.custom')}
        </button>
      </div>

      {customOpen && (
        <div className="mt-2.5 flex items-center gap-2">
          <input
            inputMode="numeric"
            aria-label={t('move.question')}
            value={customValue}
            placeholder={`1 – ${available}`}
            onChange={(event) => {
              const digits = event.target.value.replace(/\D/g, '')
              const clamped = clampMove(digits, available)
              // Show the clamped value, so 250 becomes 6 in the field itself
              // rather than being rejected after the fact.
              setCustomValue(clamped > 0 ? String(clamped) : digits ? String(available) : '')
              setQty(clamped)
            }}
            className="h-12 w-24 rounded-xl border-[1.5px] border-surface-200 px-3.5 text-lg font-bold tabular-nums text-surface-900 outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
          />
          <span className="text-xs text-surface-500">{t('move.max', { n: available })}</span>
        </div>
      )}

      {qty > 0 && toStageName && (
        <p className="mt-3 rounded-xl bg-brand-50 px-3 py-2.5 text-xs font-bold tabular-nums text-brand-700">
          {fromStageName} {available} → {available - qty}
          <span className="mx-2 text-brand-300">·</span>
          {toStageName} {toStageQty} → {toStageQty + qty}
        </p>
      )}

      <button
        type="button"
        disabled={!canMove}
        onClick={() => {
          onMove(qty)
          setQty(0)
          setCustomValue('')
          setCustomOpen(false)
        }}
        className={clsx(
          'mt-3 flex h-14 w-full items-center justify-center gap-2 rounded-2xl text-base font-extrabold transition-colors',
          canMove
            ? 'bg-brand-500 text-white hover:bg-brand-600 active:bg-brand-700'
            : 'cursor-default bg-surface-200 text-surface-400',
        )}
      >
        {toStageName
          ? qty > 0
            ? t('move.action', { stage: toStageName, n: qty })
            : t('move.actionIdle', { stage: toStageName })
          : t('move.actionIdle', { stage: '—' })}
        <span aria-hidden className="text-lg">
          →
        </span>
      </button>
    </div>
  )
}

const chipClass = (active: boolean, disabled: boolean) =>
  clsx(
    'min-h-[52px] min-w-[60px] rounded-xl px-4 text-lg font-extrabold tabular-nums transition-colors',
    disabled
      ? 'cursor-default border-[1.5px] border-surface-200 bg-surface-100 text-surface-300'
      : active
        ? 'border-2 border-brand-500 bg-brand-50 text-brand-700'
        : 'border-[1.5px] border-surface-200 bg-white text-surface-900 hover:border-surface-300',
  )

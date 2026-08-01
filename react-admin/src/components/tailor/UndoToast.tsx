/**
 * UndoToast — fifteen seconds to take it back.
 *
 * The window is a grace period, not a lock. Inside it, undo is usually purely
 * local: the movement is still in the outbox, so withdrawing the row *is* the
 * undo and nothing reaches the server. Once it has landed, the same button
 * posts a REVERSAL — which is honest, because the ledger then shows both the
 * mistake and the correction, and that is exactly what a manager asks about
 * when a tailor logs 40 instead of 4.
 *
 * The countdown is visible rather than implied. A worker deciding whether she
 * has time to check the bundle should not have to guess how long she has, and
 * the bar answers that without her reading anything.
 */

import { useEffect, useState } from 'react'
import type { UndoState } from '../../hooks/useStationQueue'
import type { Copy } from '../../lib/tailorCopy'

interface Props {
  undo: UndoState | null
  t: Copy
  onUndo: () => void
}

export default function UndoToast({ undo, t, onUndo }: Props) {
  const [remaining, setRemaining] = useState(1)

  useEffect(() => {
    if (!undo) return

    const total = Math.max(1, undo.expiresAt - Date.now())

    const tick = () => setRemaining(Math.max(0, (undo.expiresAt - Date.now()) / total))
    tick()

    const id = setInterval(tick, 100)
    return () => clearInterval(id)
  }, [undo])

  if (!undo) return null

  return (
    <div
      role="status"
      aria-live="polite"
      className="fixed inset-x-3 bottom-4 z-[70] mx-auto max-w-[496px] overflow-hidden rounded-2xl bg-surface-900 text-white shadow-lg"
    >
      <div className="flex items-center gap-3 px-3.5 py-3">
        <p className="flex-1 text-[13.5px] font-semibold">{undo.text}</p>
        <button
          type="button"
          onClick={onUndo}
          className="min-h-[44px] rounded-xl bg-white/20 px-4 text-[13.5px] font-extrabold"
        >
          {t('move.undo')}
        </button>
      </div>

      {/* Motion is decoration here — the text already says what happened. */}
      <div className="h-[3px] bg-white/20" aria-hidden>
        <div
          className="h-full bg-white motion-reduce:transition-none"
          style={{ width: `${remaining * 100}%`, transition: 'width .1s linear' }}
        />
      </div>
    </div>
  )
}

/**
 * Bottom sheets: stopping work, sending it back, and looking the job up.
 *
 * All three follow the same rule — the confirming action stays disabled until
 * the thing that makes it meaningful has been chosen. A hold without a reason
 * and a rejection without a defect are both worse than no record at all,
 * because they look like data while carrying none.
 *
 * Reason codes are fetched, never bundled. A supervisor can add one without
 * shipping an app, and each carries its own Kiswahili, so a translation can
 * never be staler than the reason it explains.
 */

import { useEffect, useState } from 'react'
import clsx from 'clsx'
import { useQuery } from '@tanstack/react-query'
import { get } from '../../api/client'
import StageRail from './StageRail'
import { moveChips } from '../../lib/productionSelectors'
import { reasonLabel, type Copy, type Lang } from '../../lib/tailorCopy'
import type { StationJob, StationStage } from '../../hooks/useStationQueue'

export interface Reason {
  code: string
  label: string
  label_sw: string | null
  applies_to: string
  is_defect: boolean
}

const useReasons = (type: 'HOLD' | 'REWORK' | 'SCRAP') =>
  useQuery<Reason[]>({
    queryKey: ['movement-reasons', type],
    queryFn: () => get<Reason[]>(`/v1/admin/production-movement/reasons?type=${type}`),
    staleTime: 10 * 60 * 1000,
  })

// --- shell -------------------------------------------------------------------

function Sheet({
  title,
  onClose,
  children,
}: {
  title: string
  onClose: () => void
  children: React.ReactNode
}) {
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label={title}
      onClick={onClose}
      className="fixed inset-0 z-[60] flex items-end justify-center bg-surface-900/45"
    >
      <div
        onClick={(e) => e.stopPropagation()}
        className="max-h-[88vh] w-full max-w-[520px] overflow-y-auto rounded-t-3xl bg-white px-4 pb-6 pt-2"
      >
        <div aria-hidden className="mx-auto my-2 h-1 w-10 rounded-full bg-surface-200" />
        <h2 className="mb-3 font-display text-xl font-semibold text-surface-900">{title}</h2>
        {children}
      </div>
    </div>
  )
}

const optionClass = (active: boolean, tone: 'danger' | 'brand' = 'danger') =>
  clsx(
    'mb-2 block min-h-[54px] w-full rounded-xl border-[1.5px] px-3.5 text-left text-sm font-bold',
    active
      ? tone === 'danger'
        ? 'border-danger bg-danger-light text-danger-dark'
        : 'border-brand-500 bg-brand-50 text-brand-700'
      : 'border-surface-200 bg-white text-surface-900',
  )

const primaryClass = (enabled: boolean, tone: 'danger' | 'brand') =>
  clsx(
    'mt-4 h-14 w-full rounded-2xl text-[15.5px] font-extrabold',
    enabled
      ? tone === 'danger'
        ? 'bg-danger text-white'
        : 'bg-brand-500 text-white'
      : 'cursor-default bg-surface-200 text-surface-400',
  )

const ghostClass =
  'mt-2 h-12 w-full rounded-xl border-[1.5px] border-surface-200 bg-white text-[13px] font-bold text-surface-600'

// --- hold --------------------------------------------------------------------

/** Stop work in place, with a reason somebody can act on. */
export function HoldSheet({
  job,
  t,
  lang,
  onClose,
  onConfirm,
}: {
  job: StationJob
  t: Copy
  lang: Lang
  onClose: () => void
  onConfirm: (quantity: number, reasonCode: string, reasonLabel: string) => void
}) {
  const available = job.my_stage.qty_ready
  const { data: reasons = [] } = useReasons('HOLD')
  const [reason, setReason] = useState<Reason | null>(null)
  const [qty, setQty] = useState(available)

  const { chips, allChip } = moveChips(available, [1, 5])

  return (
    <Sheet title={t('hold.title')} onClose={onClose}>
      <p className="-mt-1.5 mb-3.5 text-[13px] text-surface-600">
        {job.customer_name} · {job.batch_label}
      </p>

      {reasons.map((r) => (
        <button
          key={r.code}
          type="button"
          aria-pressed={reason?.code === r.code}
          onClick={() => setReason(r)}
          className={optionClass(reason?.code === r.code)}
        >
          {reasonLabel(r, lang)}
        </button>
      ))}

      <p className="mb-2 mt-3.5 text-xs font-bold text-surface-600">{t('hold.count')}</p>
      <div className="flex flex-wrap gap-2">
        {[...chips.filter((c) => c.enabled), ...(allChip ? [allChip] : [])].map((chip) => (
          <button
            key={chip.value}
            type="button"
            aria-pressed={qty === chip.value}
            onClick={() => setQty(chip.value)}
            className={clsx(
              'min-h-[52px] min-w-[60px] rounded-xl border-[1.5px] px-4 text-lg font-extrabold tabular-nums',
              qty === chip.value
                ? 'border-danger bg-danger-light text-danger-dark'
                : 'border-surface-200 bg-white text-surface-900',
            )}
          >
            {chip.value === available ? t('move.all', { n: chip.value }) : chip.label}
          </button>
        ))}
      </div>

      <button
        type="button"
        disabled={!reason}
        onClick={() => reason && onConfirm(qty, reason.code, reasonLabel(reason, lang) ?? reason.label)}
        className={primaryClass(Boolean(reason), 'danger')}
      >
        {t('hold.action')}
      </button>
      <button type="button" onClick={onClose} className={ghostClass}>
        {t('job.cancel')}
      </button>
    </Sheet>
  )
}

// --- QC send back ------------------------------------------------------------

/**
 * Rejecting needs two answers — how many, back to which bench, and why. The
 * target list comes from the workflow rather than a constant, so a line with a
 * different shape cannot offer an impossible destination.
 */
export function SendBackSheet({
  job,
  workflow,
  t,
  lang,
  onClose,
  onConfirm,
}: {
  job: StationJob
  workflow: StationStage[]
  t: Copy
  lang: Lang
  onClose: () => void
  onConfirm: (quantity: number, toStageCode: string, reasonCode: string, stageName: string) => void
}) {
  const available = job.my_stage.qty_ready
  const { data: reasons = [] } = useReasons('REWORK')
  const mine = workflow.find((s) => s.code === job.my_stage.code)

  // Earlier, on the line, never the uncut queue — the same rule the engine
  // enforces, so the picker cannot offer something that will be refused.
  const targets = workflow
    .filter((s) => mine && s.seq < mine.seq && s.kind !== 'QUEUE' && s.kind !== 'SCRAP')
    .sort((a, b) => b.seq - a.seq)

  const [target, setTarget] = useState<StationStage | null>(targets[0] ?? null)
  const [reason, setReason] = useState<Reason | null>(null)
  const [qty, setQty] = useState(1)

  const { chips, allChip } = moveChips(available, [1, 5])
  const ready = Boolean(target && reason && qty > 0)

  return (
    <Sheet title={t('qc.reason')} onClose={onClose}>
      <p className="-mt-1.5 mb-3.5 text-[13px] text-surface-600">
        {job.customer_name} · {job.batch_label}
      </p>

      {reasons.map((r) => (
        <button
          key={r.code}
          type="button"
          aria-pressed={reason?.code === r.code}
          onClick={() => setReason(r)}
          className={optionClass(reason?.code === r.code)}
        >
          {reasonLabel(r, lang)}
        </button>
      ))}

      <p className="mb-2 mt-3.5 text-xs font-bold text-surface-600">{t('qc.target')}</p>
      <div className="flex flex-wrap gap-2">
        {targets.map((stage) => (
          <button
            key={stage.code}
            type="button"
            aria-pressed={target?.code === stage.code}
            onClick={() => setTarget(stage)}
            className={clsx(
              'min-h-[48px] rounded-xl border-[1.5px] px-3.5 text-sm font-bold',
              target?.code === stage.code
                ? 'border-brand-500 bg-brand-50 text-brand-700'
                : 'border-surface-200 bg-white text-surface-900',
            )}
          >
            {stage.name}
          </button>
        ))}
      </div>

      <p className="mb-2 mt-3.5 text-xs font-bold text-surface-600">{t('move.question')}</p>
      <div className="flex flex-wrap gap-2">
        {[...chips.filter((c) => c.enabled), ...(allChip ? [allChip] : [])].map((chip) => (
          <button
            key={chip.value}
            type="button"
            aria-pressed={qty === chip.value}
            onClick={() => setQty(chip.value)}
            className={clsx(
              'min-h-[52px] min-w-[60px] rounded-xl border-[1.5px] px-4 text-lg font-extrabold tabular-nums',
              qty === chip.value
                ? 'border-brand-500 bg-brand-50 text-brand-700'
                : 'border-surface-200 bg-white text-surface-900',
            )}
          >
            {chip.value === available ? t('move.all', { n: chip.value }) : chip.label}
          </button>
        ))}
      </div>

      <button
        type="button"
        disabled={!ready}
        onClick={() =>
          target && reason && onConfirm(qty, target.code, reason.code, target.name)
        }
        className={primaryClass(ready, 'danger')}
      >
        {t('qc.reject')}
      </button>
      <button type="button" onClick={onClose} className={ghostClass}>
        {t('job.cancel')}
      </button>
    </Sheet>
  )
}

// --- job detail --------------------------------------------------------------

/**
 * What she is making, what to check, and what has happened to these pieces.
 *
 * The checklist is local and non-blocking on purpose: it guides, it does not
 * gate. A tailor who cannot tick a box must still be able to move work, or she
 * stops using the screen entirely and the ledger goes quiet.
 */
export function DetailSheet({
  job,
  workflow,
  t,
  onClose,
}: {
  job: StationJob
  workflow: StationStage[]
  t: Copy
  onClose: () => void
}) {
  const [checked, setChecked] = useState<number[]>([])

  const { data: history = [] } = useQuery<any[]>({
    queryKey: ['batch-history', job.batch_id],
    queryFn: async () => {
      const page = await get<{ data: any[] }>(
        `/v1/admin/production-movement/batches/${job.batch_id}/history?per_page=20`,
      )
      return page.data ?? []
    },
  })

  return (
    <Sheet title={job.customer_name ?? job.order_ref} onClose={onClose}>
      <p className="-mt-1.5 mb-3.5 text-[13px] text-surface-600">
        {job.garment} · {job.batch_label} · {job.order_ref}
      </p>

      <StageRail
        workflow={workflow}
        distribution={job.distribution}
        held={job.held}
        myStageCode={job.my_stage.code}
      />

      {job.spec.length > 0 && (
        <>
          <SectionLabel>{t('job.spec')}</SectionLabel>
          <div className="overflow-hidden rounded-xl border border-surface-200">
            {job.spec.map((row, i) => (
              <div
                key={row.label}
                className={clsx(
                  'flex justify-between gap-3 px-3.5 py-2.5',
                  i % 2 ? 'bg-surface-50' : 'bg-white',
                )}
              >
                <span className="text-[12.5px] font-semibold text-surface-500">{row.label}</span>
                <span className="text-right text-[12.5px] font-semibold text-surface-900">
                  {row.value}
                </span>
              </div>
            ))}
          </div>
        </>
      )}

      {job.checklist.length > 0 && (
        <>
          <SectionLabel>{t('job.checklist')}</SectionLabel>
          {job.checklist.map((item, i) => (
            <button
              key={item}
              type="button"
              aria-pressed={checked.includes(i)}
              onClick={() =>
                setChecked((c) => (c.includes(i) ? c.filter((x) => x !== i) : [...c, i]))
              }
              className={clsx(
                'mb-2 flex min-h-[52px] w-full items-center gap-3 rounded-xl border-[1.5px] px-3.5 text-left',
                checked.includes(i)
                  ? 'border-success bg-success-light'
                  : 'border-surface-200 bg-white',
              )}
            >
              <span
                aria-hidden
                className={clsx(
                  'grid h-6 w-6 shrink-0 place-items-center rounded-md border-2 text-sm font-black text-white',
                  checked.includes(i) ? 'border-success bg-success' : 'border-surface-200 bg-white',
                )}
              >
                {checked.includes(i) ? '✓' : ''}
              </span>
              <span className="text-[13.5px] font-semibold text-surface-900">{item}</span>
            </button>
          ))}
        </>
      )}

      <SectionLabel>{t('job.history')}</SectionLabel>
      {history.slice(0, 12).map((m, i) => (
        <div
          key={m.id ?? i}
          className={clsx(
            'flex justify-between gap-2.5 py-2.5',
            i < history.length - 1 && 'border-b border-surface-200',
          )}
        >
          <div>
            <p className="text-[13px] font-bold text-surface-900">
              {m.from_stage?.name ?? '—'} → {m.to_stage?.name ?? '—'}
            </p>
            <p className="text-[11.5px] text-surface-500">
              {[m.mover?.first_name, m.mover?.last_name].filter(Boolean).join(' ')}
              {m.reason?.label ? ` · ${m.reason.label}` : ''}
            </p>
          </div>
          <span className="whitespace-nowrap text-[12.5px] font-bold tabular-nums text-surface-600">
            {m.quantity}
          </span>
        </div>
      ))}

      <button type="button" onClick={onClose} className={clsx(ghostClass, 'mt-4 h-13')}>
        {t('job.close')}
      </button>
    </Sheet>
  )
}

function SectionLabel({ children }: { children: React.ReactNode }) {
  return (
    <div className="mx-1 mb-2 mt-5 flex items-center gap-2 text-[11.5px] font-extrabold uppercase tracking-widest text-surface-500">
      <span>{children}</span>
      <span className="h-px flex-1 bg-surface-200" />
    </div>
  )
}

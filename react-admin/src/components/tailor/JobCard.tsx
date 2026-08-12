/**
 * JobCard — one batch, one decision.
 *
 * The card carries a lot of context, so the hierarchy has to do real work:
 * exactly one primary action, everything else visually quiet. The customer's
 * name is the only thing set in the display face — it is who the garment is
 * for, not operational data — and every count is tabular so columns of numbers
 * stay readable at a glance.
 *
 * Four modes, driven by where the pieces are rather than by four screens:
 *
 *   rework   — sent back, with the reason visible and never behind a tap
 *   ready    — workable, so the move sheet is offered
 *   held     — stopped, so the decision is release, not move
 *   upstream — nothing here yet, so the card says what is coming instead of
 *              showing an empty bench
 *
 * QC is the one station that must NOT have a single dominant action: a big
 * green Pass beside a quiet "send back" link is how defects get waved through.
 * There, Pass and Send back are rendered the same size and weight in different
 * colours.
 */

import clsx from 'clsx'
import StageRail from './StageRail'
import MoveSheet from './MoveSheet'
import type { StationJob, StationPayload, StationStage } from '../../hooks/useStationQueue'
import { dueLabel, reasonLabel, type Copy, type Lang } from '../../lib/tailorCopy'

export type JobMode = 'rework' | 'ready' | 'held' | 'upstream'

interface Props {
  job: StationJob
  mode: JobMode
  station: StationPayload['station']
  workflow: StationStage[]
  t: Copy
  lang: Lang
  onMove: (job: StationJob, quantity: number) => void
  onSendBack: (job: StationJob) => void
  onHold: (job: StationJob) => void
  onRelease: (job: StationJob) => void
  onOpen: (job: StationJob) => void
}

export default function JobCard({
  job,
  mode,
  station,
  workflow,
  t,
  lang,
  onMove,
  onSendBack,
  onHold,
  onRelease,
  onOpen,
}: Props) {
  const urgent = job.priority === 'urgent' || (job.days_left !== null && job.days_left <= 1)
  const due = dueLabel(job.days_left, job.due_at, t)
  const isGate = station.kind === 'INSPECT'
  const nextQty = station.next_stage_code ? (job.distribution[station.next_stage_code] ?? 0) : 0

  return (
    <article
      className={clsx(
        'mb-3 rounded-2xl border bg-white p-3.5 shadow-sm',
        mode === 'rework' ? 'border-danger' : 'border-surface-200',
      )}
    >
      {/* Who and what */}
      <header className="flex items-start gap-2.5">
        <span
          aria-hidden
          className="mt-0.5 h-8 w-8 shrink-0 rounded-lg border border-surface-200"
          style={{ background: job.swatch_hex ?? '#e7eae6' }}
        />
        <div className="min-w-0 flex-1">
          <h3 className="font-display text-lg font-semibold leading-tight text-surface-900">
            {job.customer_name ?? job.order_ref}
          </h3>
          <p className="mt-0.5 text-[12.5px] text-surface-600">{job.garment}</p>

          <div className="mt-1.5 flex flex-wrap gap-1.5">
            <Chip>{job.batch_label}</Chip>
            <Chip>{job.order_ref}</Chip>
            {due && (
              <Chip tone={urgent ? 'danger' : 'default'}>
                {urgent ? '⚑ ' : ''}
                {due}
              </Chip>
            )}
          </div>
        </div>
      </header>

      {mode === 'rework' && job.rework && (
        <ReworkBanner
          qty={job.rework.qty}
          reason={reasonLabel(job.rework, lang)}
          fromStage={job.rework.from_stage}
          t={t}
        />
      )}

      {job.handover_note && mode !== 'upstream' && (
        <HandoverNote note={job.handover_note} t={t} />
      )}

      <div className="mt-3.5">
        <StageRail
          workflow={workflow}
          distribution={job.distribution}
          held={job.held}
          myStageCode={job.my_stage.code}
        />
      </div>

      {mode === 'held' && job.hold && (
        <div className="mt-3 flex items-center justify-between gap-2.5 rounded-xl bg-danger-light px-3 py-2.5">
          <div>
            <p className="text-xs font-extrabold text-danger-dark">
              {job.hold.qty} {t('group.held').toLowerCase()}
            </p>
            <p className="mt-0.5 text-xs text-surface-900">{reasonLabel(job.hold, lang)}</p>
          </div>
          <button type="button" onClick={() => onRelease(job)} className={ghostClass}>
            {t('hold.release')}
          </button>
        </div>
      )}

      {mode === 'upstream' && (
        <PullSignal job={job} workflow={workflow} t={t} />
      )}

      {/* The one decision */}
      {job.my_stage.qty_ready > 0 && (
        isGate ? (
          <QcActions
            job={job}
            station={station}
            t={t}
            onPass={() => onMove(job, job.my_stage.qty_ready)}
            onSendBack={() => onSendBack(job)}
          />
        ) : (
          <MoveSheet
            available={job.my_stage.qty_ready}
            fromStageName={job.my_stage.name}
            toStageName={station.next_stage_name}
            toStageQty={nextQty}
            t={t}
            onMove={(quantity) => onMove(job, quantity)}
          />
        )
      )}

      {/* Secondary actions — deliberately quiet */}
      <div className="mt-2.5 flex gap-2">
        <button type="button" onClick={() => onOpen(job)} className={clsx(ghostClass, 'flex-1')}>
          {t('job.details')}
        </button>
        {station.can_hold && mode !== 'held' && job.my_stage.qty_ready > 0 && (
          <button
            type="button"
            onClick={() => onHold(job)}
            className={clsx(ghostClass, 'flex-1 text-danger')}
          >
            {t('job.problem')}
          </button>
        )}
      </div>
    </article>
  )
}

/**
 * Why work came back. The reason is always on the face of the card — a defect
 * hidden behind a tap is a defect that gets repeated.
 */
export function ReworkBanner({
  qty,
  reason,
  fromStage,
  t,
}: {
  qty: number
  reason: string | null
  fromStage: string | null
  t: Copy
}) {
  return (
    <div className="mt-3 rounded-xl border-l-[3px] border-danger bg-danger-light px-3 py-2.5">
      <p className="text-xs font-extrabold uppercase tracking-wide text-danger-dark">
        {qty} · {fromStage ? `${t('group.rework')} (${fromStage})` : t('group.rework')}
      </p>
      {reason && <p className="mt-0.5 text-[12.5px] text-surface-900">{reason}</p>}
    </div>
  )
}

/**
 * The message that came with the work — the note on the movement that
 * delivered it, attributed to whoever posted it.
 */
export function HandoverNote({
  note,
  t,
}: {
  note: NonNullable<StationJob['handover_note']>
  t: Copy
}) {
  return (
    <div className="mt-2.5 rounded-xl border-l-[3px] border-warning bg-warning-light px-3 py-2.5">
      <p className="text-[10.5px] font-extrabold uppercase tracking-wider text-warning-dark">
        {t('job.handover', { stage: note.from_stage ?? '—', name: note.by_name ?? '—' })}
      </p>
      <p className="mt-0.5 text-[12.5px] text-surface-900">{note.text}</p>
    </div>
  )
}

/** An idle bench is told why it is idle, never shown a blank card. */
export function PullSignal({
  job,
  workflow,
  t,
}: {
  job: StationJob
  workflow: StationStage[]
  t: Copy
}) {
  const mine = workflow.find((s) => s.code === job.my_stage.code)
  const upstream = workflow
    .filter((s) => mine && s.seq < mine.seq && (job.distribution[s.code] ?? 0) > 0)
    .sort((a, b) => b.seq - a.seq)[0]

  return (
    <p className="mt-3 rounded-xl bg-surface-100 px-3 py-2.5 text-[12.5px] text-surface-600">
      {upstream
        ? t('empty.pull', { stage: upstream.name, n: job.distribution[upstream.code] ?? 0 })
        : t('empty.quiet')}
    </p>
  )
}

/**
 * QC. Two buttons, equal size and weight, different colour.
 *
 * This is the one screen that must not have a dominant action — a prominent
 * Pass beside a quiet Send back is how defects get waved through, and the
 * weekly Pareto only works if rejecting is as easy as accepting.
 */
export function QcActions({
  job,
  station,
  t,
  onPass,
  onSendBack,
}: {
  job: StationJob
  station: StationPayload['station']
  t: Copy
  onPass: () => void
  onSendBack: () => void
}) {
  return (
    <div className="mt-3.5">
      <p className="text-xs font-bold text-surface-600">
        {job.my_stage.qty_ready} {t('station.waiting')}
      </p>
      <div className="mt-2 grid grid-cols-2 gap-2">
        <button
          type="button"
          onClick={onPass}
          className="flex h-14 items-center justify-center rounded-2xl bg-success text-base font-extrabold text-white hover:bg-success-dark"
        >
          {t('qc.pass', { stage: station.next_stage_name ?? '—' })}
        </button>
        <button
          type="button"
          onClick={onSendBack}
          className="flex h-14 items-center justify-center rounded-2xl bg-danger text-base font-extrabold text-white hover:bg-danger-dark"
        >
          {t('qc.reject')}
        </button>
      </div>
    </div>
  )
}

function Chip({
  children,
  tone = 'default',
}: {
  children: React.ReactNode
  tone?: 'default' | 'danger'
}) {
  return (
    <span
      className={clsx(
        'inline-flex items-center rounded-full border px-2 py-0.5 text-[11.5px] font-semibold',
        tone === 'danger'
          ? 'border-transparent bg-danger-light text-danger-dark'
          : 'border-surface-200 bg-surface-100 text-surface-600',
      )}
    >
      {children}
    </span>
  )
}

const ghostClass =
  'min-h-[46px] rounded-xl border-[1.5px] border-surface-200 bg-white px-3.5 text-[13px] font-bold text-surface-600 hover:border-surface-300'

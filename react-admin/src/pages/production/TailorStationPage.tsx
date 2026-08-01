/**
 * TailorStationPage — the ledger-backed station screen.
 *
 * Strangler pattern: this is a new page behind the `tailor_ledger_ui` flag.
 * `TailorWorkspacePage.tsx` is left untouched and stays reachable until the
 * flag retires, so turning the flag off returns a station to the old screen in
 * one release with no data loss — the ledger keeps recording either way,
 * because both screens now write the same movements.
 *
 * The change this page makes is one question, asked differently:
 *
 *     before   "how many are done?"        → quantity_done = 5
 *     after    "how many are you handing   → FORWARD · 5 · Sewing → Buttons
 *               to Button fixing?"
 *
 * Same tap count. What changes is that two tailors tapping at once can no
 * longer overwrite each other, a mis-tap can be undone, and the answer to
 * "where are my pieces" is on every card.
 */

import { useMemo, useState } from 'react'
import { useParams } from 'react-router-dom'
import clsx from 'clsx'
import { useStationQueue, type StationJob } from '../../hooks/useStationQueue'
import { makeCopy, type Lang } from '../../lib/tailorCopy'
import JobCard, { type JobMode } from '../../components/tailor/JobCard'
import { DetailSheet, HoldSheet, SendBackSheet } from '../../components/tailor/Sheets'
import UndoToast from '../../components/tailor/UndoToast'

export default function TailorStationPage() {
  const { stageCode = 'SEWING' } = useParams<{ stageCode: string }>()
  const [lang, setLang] = useState<Lang>(() =>
    (localStorage.getItem('tailor.lang') as Lang) ?? 'en',
  )
  const t = useMemo(() => makeCopy(lang), [lang])

  const station = useStationQueue(stageCode, lang)
  const [detail, setDetail] = useState<StationJob | null>(null)
  const [holding, setHolding] = useState<StationJob | null>(null)
  const [sendingBack, setSendingBack] = useState<StationJob | null>(null)

  const payload = station.data
  const waiting = useMemo(
    () =>
      payload
        ? [...payload.groups.rework, ...payload.groups.ready].reduce(
            (sum, j) => sum + j.my_stage.qty_ready,
            0,
          )
        : 0,
    [payload],
  )

  const toggleLang = () => {
    const next: Lang = lang === 'en' ? 'sw' : 'en'
    setLang(next)
    localStorage.setItem('tailor.lang', next)
  }

  if (station.isLoading) {
    return <div className="p-6 text-sm text-surface-500">…</div>
  }

  if (!payload?.station) {
    return (
      <div className="p-6 text-sm text-surface-600">
        {station.error ? (station.error as any).message : t('empty.title')}
      </div>
    )
  }

  const { station: info, workflow, shift, groups } = payload

  const move = (job: StationJob, quantity: number) =>
    station.move(
      {
        batchId: job.batch_id,
        fromStageCode: job.my_stage.code,
        quantity,
        type: 'FORWARD',
      },
      t('move.done', { n: quantity, stage: info.next_stage_name ?? '' }),
    )

  const sections: { key: JobMode; label: string; jobs: StationJob[]; tone?: string }[] = [
    { key: 'rework', label: t('group.rework'), jobs: groups.rework, tone: 'text-danger' },
    { key: 'ready', label: t('group.ready'), jobs: groups.ready },
    { key: 'held', label: t('group.held'), jobs: groups.held, tone: 'text-danger' },
    { key: 'upstream', label: t('group.upstream'), jobs: groups.upstream },
  ]

  return (
    <div className="min-h-screen bg-surface-canvas pb-28">
      <div className="mx-auto max-w-[520px]">
        {/* Station bar */}
        <header className="sticky top-0 z-40 rounded-b-2xl bg-brand-600 px-4 pb-3.5 pt-3 text-white">
          <div className="flex items-center justify-between">
            <div>
              <h1 className="text-[17px] font-extrabold leading-tight">{info.stage_name}</h1>
              <p className="text-[11.5px] opacity-80">{info.stage_code}</p>
            </div>

            <div className="flex items-center gap-2">
              <button
                type="button"
                onClick={toggleLang}
                aria-label="Language"
                className="min-h-[44px] rounded-full border border-white/35 px-3.5 text-xs font-bold"
              >
                {lang === 'en' ? 'SW' : 'EN'}
              </button>
              <span
                className={clsx(
                  'flex min-h-[44px] items-center gap-1.5 rounded-full px-3.5 text-xs font-bold',
                  station.online ? 'bg-white/20' : 'bg-warning text-white',
                )}
              >
                <span
                  aria-hidden
                  className={clsx(
                    'h-1.5 w-1.5 rounded-full',
                    station.online ? 'bg-success-vivid' : 'bg-white',
                  )}
                />
                {station.online
                  ? station.queued > 0
                    ? t('sync.queued', { n: station.queued })
                    : t('sync.ok')
                  : t('sync.offline')}
              </span>
            </div>
          </div>

          <div className="mt-3 flex gap-2">
            <Stat value={waiting} label={t('station.waiting')} />
            <Stat
              value={shift.moved_this_hour}
              suffix={shift.hour_target ? `/ ${shift.hour_target}` : undefined}
              label={t('station.thisHour')}
            />
            <Stat
              value={shift.moved_today}
              suffix={shift.target ? `/ ${shift.target}` : undefined}
              label={t('station.movedToday')}
            />
          </div>
        </header>

        {/* Conflict — someone got there first */}
        {station.conflict && (
          <div
            role="alert"
            className="mx-3 mt-3 flex items-start justify-between gap-3 rounded-xl bg-warning-light px-3.5 py-3"
          >
            <p className="text-[13px] font-semibold text-warning-dark">
              {t('move.conflict', {
                stage: station.conflict.stageName,
                n: station.conflict.available,
              })}
            </p>
            <button
              type="button"
              onClick={station.dismissConflict}
              className="text-[13px] font-bold text-warning-dark underline"
            >
              OK
            </button>
          </div>
        )}

        <div className="px-3">
          {sections.map(({ key, label, jobs, tone }) =>
            jobs.length === 0 && key !== 'ready' ? null : (
              <section key={key}>
                <h2
                  className={clsx(
                    'mx-1 mb-2 mt-5 flex items-center gap-2 text-[11.5px] font-extrabold uppercase tracking-widest',
                    tone ?? 'text-surface-500',
                  )}
                >
                  <span>{label}</span>
                  <span className="h-px flex-1 bg-surface-200" />
                </h2>

                {jobs.length === 0 ? (
                  <div className="rounded-2xl border border-dashed border-surface-200 bg-white px-4 py-7 text-center">
                    <p className="font-display text-[17px] text-surface-900">{t('empty.title')}</p>
                    <p className="mt-1.5 text-[13px] text-surface-600">
                      {groups.upstream[0]
                        ? t('empty.pull', {
                            stage: groups.upstream[0].my_stage.name,
                            n: groups.upstream[0].loaded_qty,
                          })
                        : t('empty.quiet')}
                    </p>
                  </div>
                ) : (
                  jobs.map((job) => (
                    <JobCard
                      key={`${key}-${job.batch_id}`}
                      job={job}
                      mode={key}
                      station={info}
                      workflow={workflow}
                      t={t}
                      lang={lang}
                      onMove={move}
                      onSendBack={setSendingBack}
                      onHold={setHolding}
                      onRelease={(j) =>
                        station.move(
                          {
                            batchId: j.batch_id,
                            fromStageCode: j.my_stage.code,
                            quantity: j.my_stage.qty_held,
                            type: 'RELEASE',
                          },
                          t('hold.released', { n: j.my_stage.qty_held }),
                        )
                      }
                      onOpen={setDetail}
                    />
                  ))
                )}
              </section>
            ),
          )}
        </div>
      </div>

      {detail && (
        <DetailSheet job={detail} workflow={workflow} t={t} onClose={() => setDetail(null)} />
      )}

      {holding && (
        <HoldSheet
          job={holding}
          t={t}
          lang={lang}
          onClose={() => setHolding(null)}
          onConfirm={(quantity, reasonCode, label) => {
            const job = holding
            setHolding(null)
            void station.move(
              {
                batchId: job.batch_id,
                fromStageCode: job.my_stage.code,
                quantity,
                type: 'HOLD',
                reasonCode,
              },
              `${quantity} · ${label}`,
            )
          }}
        />
      )}

      {sendingBack && (
        <SendBackSheet
          job={sendingBack}
          workflow={workflow}
          t={t}
          lang={lang}
          onClose={() => setSendingBack(null)}
          onConfirm={(quantity, toStageCode, reasonCode, stageName) => {
            const job = sendingBack
            setSendingBack(null)
            void station.move(
              {
                batchId: job.batch_id,
                fromStageCode: job.my_stage.code,
                toStageCode,
                quantity,
                type: 'REWORK',
                reasonCode,
              },
              t('qc.sent', { n: quantity, stage: stageName }),
            )
          }}
        />
      )}

      <UndoToast undo={station.undo} t={t} onUndo={station.undoLast} />
    </div>
  )
}

function Stat({
  value,
  suffix,
  label,
}: {
  value: number
  suffix?: string
  label: string
}) {
  return (
    <div className="flex-1 rounded-xl bg-white/15 px-3 py-2.5">
      <div className="flex items-baseline gap-1">
        <span className="text-[26px] font-extrabold leading-none tabular-nums">{value}</span>
        {suffix && <span className="text-xs opacity-70">{suffix}</span>}
      </div>
      <p className="mt-0.5 text-[11px] opacity-85">{label}</p>
    </div>
  )
}

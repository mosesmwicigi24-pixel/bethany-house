/**
 * useStationQueue.ts
 *
 * The data half of the tailor screen: one fetch, optimistic movement, typed
 * rollback, a 15-second undo, and an outbox that makes all of it safe on a
 * workshop connection.
 *
 * The rule the whole hook is built around: **apply locally first, reconcile
 * after.** A tailor taps Move and the number changes immediately, because
 * waiting for a round trip on one bar of signal is what makes people tap twice.
 * The server's response then replaces the local guess outright — every write
 * echoes the batch's authoritative distribution, so there is never a merge to
 * get wrong.
 *
 * When the server refuses, the rollback is typed rather than generic:
 * `INSUFFICIENT_QUANTITY` carries the real quantity, so the worker is told
 * "Cutting now has 2" instead of being shown an error and left to guess.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { get, post } from '../api/client'
import type { ApiError } from '../types'
import * as outbox from '../lib/movementOutbox'
import type { MovementType, QueuedMovement } from '../lib/movementOutbox'

// --- shapes the station endpoint returns ------------------------------------

export interface StationStage {
  code: string
  name: string
  seq: number
  kind: 'QUEUE' | 'WORK' | 'INSPECT' | 'DONE' | 'SCRAP'
  icon: string | null
  color: string | null
  wip_limit: number | null
}

export interface StationJob {
  batch_id: number
  order_id: number
  order_ref: string
  customer_name: string | null
  garment: string | null
  batch_label: string
  swatch_hex: string | null
  ordered_qty: number
  loaded_qty: number
  due_at: string | null
  days_left: number | null
  priority: string
  distribution: Record<string, number>
  held: Record<string, number>
  my_stage: {
    stage_id: number
    code: string
    name: string
    kind: StationStage['kind']
    qty_ready: number
    qty_held: number
  }
  rework: { qty: number; reason_label: string | null; reason_sw: string | null; from_stage: string | null; at: string } | null
  hold: { qty: number; reason_code: string | null; reason_label: string | null; reason_sw: string | null; at: string | null } | null
  handover_note: { from_stage: string | null; by_name: string | null; text: string; at: string } | null
  spec: { label: string; value: string }[]
  checklist: string[]
  spec_image_url: string | null
}

export interface StationPayload {
  station: {
    stage_code: string
    stage_name: string
    kind: StationStage['kind']
    icon: string | null
    color: string | null
    next_stage_code: string | null
    next_stage_name: string | null
    can_hold: boolean
    can_rework: boolean
    can_scrap: boolean
    can_intake: boolean
  }
  shift: {
    moved_today: number
    target: number | null
    moved_this_hour: number
    hour_target: number | null
  }
  workflow: StationStage[]
  groups: {
    rework: StationJob[]
    ready: StationJob[]
    held: StationJob[]
    upstream: StationJob[]
  }
  ledger_ui: boolean
}

export interface MoveRequest {
  batchId: number
  fromStageCode: string
  toStageCode?: string | null
  quantity: number
  type: MovementType
  reasonCode?: string | null
  note?: string | null
}

export interface UndoState {
  clientRef: string
  movementId: number | null
  text: string
  expiresAt: number
}

export interface Conflict {
  batchId: number
  stageName: string
  available: number
  message: string
}

const BASE = '/v1/admin/production-movement'

/** How long a mis-tap can be taken back before it needs a supervisor. */
export const UNDO_MS = 15_000

export function useStationQueue(stageCode: string, lang: 'en' | 'sw' = 'en') {
  const qc = useQueryClient()
  const queryKey = useMemo(() => ['station-queue', stageCode], [stageCode])

  const [online, setOnline] = useState(() => navigator.onLine)
  const [queued, setQueued] = useState(0)
  const [undo, setUndo] = useState<UndoState | null>(null)
  const [conflict, setConflict] = useState<Conflict | null>(null)
  const undoTimer = useRef<ReturnType<typeof setTimeout> | null>(null)

  const query = useQuery<StationPayload>({
    queryKey,
    queryFn: () => get<StationPayload>(`${BASE}/stations/${stageCode}/queue`),
    // The floor moves without telling us; a stale queue is how two tailors end
    // up reaching for the same ten pieces.
    refetchInterval: 30_000,
    refetchOnWindowFocus: true,
  })

  useEffect(() => outbox.subscribe(setQueued), [])

  useEffect(() => {
    const up = () => setOnline(true)
    const down = () => setOnline(false)
    window.addEventListener('online', up)
    window.addEventListener('offline', down)
    return () => {
      window.removeEventListener('online', up)
      window.removeEventListener('offline', down)
    }
  }, [])

  useEffect(() => () => {
    if (undoTimer.current) clearTimeout(undoTimer.current)
  }, [])

  /**
   * Apply a movement to the cached queue without waiting for the server.
   *
   * Only the pieces move — the job stays exactly where it is in its group until
   * the next fetch, because a card vanishing under a thumb mid-tap is how the
   * wrong batch gets logged.
   */
  const applyLocally = useCallback(
    (req: MoveRequest) => {
      qc.setQueryData<StationPayload>(queryKey, (prev) => {
        if (!prev) return prev

        const patchJob = (job: StationJob): StationJob => {
          if (job.batch_id !== req.batchId) return job

          const from = req.fromStageCode
          const to = req.toStageCode ?? prev.station.next_stage_code ?? from
          const dist = { ...job.distribution }
          const held = { ...job.held }
          const mine = { ...job.my_stage }

          if (req.type === 'HOLD') {
            mine.qty_ready -= req.quantity
            mine.qty_held += req.quantity
            held[from] = (held[from] ?? 0) + req.quantity
          } else if (req.type === 'RELEASE') {
            mine.qty_held -= req.quantity
            mine.qty_ready += req.quantity
            held[from] = Math.max(0, (held[from] ?? 0) - req.quantity)
          } else {
            // FORWARD, REWORK and SCRAP all move pieces out of this station.
            dist[from] = Math.max(0, (dist[from] ?? 0) - req.quantity)
            dist[to] = (dist[to] ?? 0) + req.quantity
            mine.qty_ready -= req.quantity
          }

          return { ...job, distribution: dist, held, my_stage: mine }
        }

        return {
          ...prev,
          shift:
            req.type === 'FORWARD'
              ? {
                  ...prev.shift,
                  moved_today: prev.shift.moved_today + req.quantity,
                  moved_this_hour: prev.shift.moved_this_hour + req.quantity,
                }
              : prev.shift,
          groups: {
            rework: prev.groups.rework.map(patchJob),
            ready: prev.groups.ready.map(patchJob),
            held: prev.groups.held.map(patchJob),
            upstream: prev.groups.upstream.map(patchJob),
          },
        }
      })
    },
    [qc, queryKey],
  )

  /** Replace the local guess with what the server says is true. */
  const reconcile = useCallback(
    (batchId: number, distribution: Record<string, number>, held: Record<string, number>) => {
      qc.setQueryData<StationPayload>(queryKey, (prev) => {
        if (!prev) return prev

        const patch = (job: StationJob): StationJob =>
          job.batch_id === batchId
            ? {
                ...job,
                distribution,
                held,
                my_stage: {
                  ...job.my_stage,
                  qty_ready: (distribution[job.my_stage.code] ?? 0) - (held[job.my_stage.code] ?? 0),
                  qty_held: held[job.my_stage.code] ?? 0,
                },
              }
            : job

        return {
          ...prev,
          groups: {
            rework: prev.groups.rework.map(patch),
            ready: prev.groups.ready.map(patch),
            held: prev.groups.held.map(patch),
            upstream: prev.groups.upstream.map(patch),
          },
        }
      })
    },
    [qc, queryKey],
  )

  const armUndo = useCallback((state: UndoState) => {
    if (undoTimer.current) clearTimeout(undoTimer.current)
    setUndo(state)
    undoTimer.current = setTimeout(() => setUndo(null), UNDO_MS)
  }, [])

  const send = useCallback(
    async (m: QueuedMovement) =>
      post<{ movement: { id: number }; distribution: any; replayed: boolean }>(
        `${BASE}/batches/${m.batchId}/movements`,
        {
          client_ref: m.clientRef,
          from_stage_code: m.fromStageCode,
          to_stage_code: m.toStageCode ?? undefined,
          quantity: m.quantity,
          type: m.type,
          reason_code: m.reasonCode ?? undefined,
          note: m.note ?? undefined,
          device_id: navigator.userAgent.slice(0, 100),
        },
      ),
    [],
  )

  /**
   * The one write path the screen uses.
   *
   * Outbox first, then optimistic apply, then the request — in that order, so
   * nothing is lost between the tap and the network.
   */
  const move = useCallback(
    async (req: MoveRequest, undoText: string) => {
      setConflict(null)

      const row = outbox.enqueue({
        batchId: req.batchId,
        fromStageCode: req.fromStageCode,
        toStageCode: req.toStageCode ?? null,
        quantity: req.quantity,
        type: req.type,
        reasonCode: req.reasonCode ?? null,
        note: req.note ?? null,
      })

      applyLocally(req)
      armUndo({ clientRef: row.clientRef, movementId: null, text: undoText, expiresAt: Date.now() + UNDO_MS })

      try {
        const result = await send(row)
        outbox.settle(row.clientRef)

        if (result?.distribution) {
          reconcile(
            req.batchId,
            distributionByCode(result.distribution),
            heldByCode(result.distribution),
          )
        }

        setUndo((u) =>
          u && u.clientRef === row.clientRef ? { ...u, movementId: result?.movement?.id ?? null } : u,
        )

        return { ok: true as const }
      } catch (err) {
        const api = err as ApiError

        // Offline: the tap is safe in the outbox and the optimistic state
        // stands. It will drain when the signal comes back.
        if (!api.status) {
          return { ok: true as const, queued: true }
        }

        outbox.settle(row.clientRef)
        setUndo(null)
        qc.invalidateQueries({ queryKey })

        if (api.code === 'INSUFFICIENT_QUANTITY') {
          setConflict({
            batchId: req.batchId,
            stageName: req.fromStageCode,
            available: Number(api.detail?.available ?? 0),
            message: api.message,
          })
        }

        return { ok: false as const, error: api }
      }
    },
    [applyLocally, armUndo, qc, queryKey, reconcile, send],
  )

  /**
   * Take it back.
   *
   * While the movement is still queued this is purely local — the tap never
   * reached the server, so dropping the row is the whole undo. Once it has
   * landed the correction is a REVERSAL with a new ref, which is honest: the
   * ledger shows both the mistake and the fix.
   */
  const undoLast = useCallback(async () => {
    if (!undo) return

    const current = undo
    setUndo(null)

    if (outbox.withdraw(current.clientRef)) {
      qc.invalidateQueries({ queryKey })
      return
    }

    if (current.movementId) {
      try {
        await post(`${BASE}/movements/${current.movementId}/reverse`, {
          client_ref: outbox.newClientRef(),
          note: 'Undone by the tailor within the grace period.',
        })
      } catch {
        /* surfaced by the refetch below — the ledger, not the screen, is truth */
      }
    }

    qc.invalidateQueries({ queryKey })
  }, [qc, queryKey, undo])

  /** Push whatever is waiting, oldest first. */
  const flush = useCallback(async () => {
    const result = await outbox.drain(async (m) => {
      try {
        await send(m)
        return { ok: true as const }
      } catch (err) {
        const api = err as ApiError
        // No status means the network, not the server: worth retrying.
        return { ok: false as const, retry: !api.status }
      }
    })

    if (result.sent > 0) qc.invalidateQueries({ queryKey })
    return result
  }, [qc, queryKey, send])

  useEffect(() => {
    if (online && outbox.pendingCount() > 0) void flush()
  }, [online, flush])

  return {
    ...query,
    online,
    queued,
    undo,
    conflict,
    dismissConflict: () => setConflict(null),
    move,
    undoLast,
    flush,
  }
}

// The write response reports full stage rows; the cards only need the counts.
const distributionByCode = (d: any): Record<string, number> =>
  Object.fromEntries((d.stages ?? []).map((s: any) => [s.stage_code, s.qty_total]))

const heldByCode = (d: any): Record<string, number> =>
  Object.fromEntries(
    (d.stages ?? []).filter((s: any) => s.qty_held > 0).map((s: any) => [s.stage_code, s.qty_held]),
  )

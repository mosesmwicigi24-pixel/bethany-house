/**
 * movementOutbox.ts
 *
 * A workshop has one bar of signal and a tailor has both hands full. Every
 * movement is therefore written to a local queue **before** the network call,
 * never after: if the request never leaves, the tap is still recorded, and if
 * the phone dies between send and response the movement is still there to
 * retry.
 *
 * Safe to retry because the server deduplicates on `client_ref`, which is
 * generated here, on the device, before anything is sent. That single
 * decision is what makes a double-tap, a flaky reconnect and a flushed
 * offline queue all produce exactly one movement.
 *
 * Deliberately dependency-free and synchronous over `localStorage`: the queue
 * has to survive a page reload and a killed tab, and a handful of pending taps
 * is far too small to justify IndexedDB. Everything here is pure enough to test
 * without a browser.
 */

export type MovementType = 'FORWARD' | 'REWORK' | 'SCRAP' | 'HOLD' | 'RELEASE'

export interface QueuedMovement {
  clientRef: string
  batchId: number
  fromStageCode: string
  toStageCode?: string | null
  quantity: number
  type: MovementType
  reasonCode?: string | null
  note?: string | null
  /** Wall-clock of the tap, so a drained queue can be reported in order. */
  queuedAt: number
  /** Retries so far — a movement the server keeps refusing must not spin. */
  attempts: number
}

const KEY = 'production.movement.outbox.v1'

/** Beyond this a movement is parked rather than retried forever. */
export const MAX_ATTEMPTS = 6

const read = (): QueuedMovement[] => {
  try {
    const raw = localStorage.getItem(KEY)
    const parsed = raw ? JSON.parse(raw) : []
    return Array.isArray(parsed) ? parsed : []
  } catch {
    // A corrupt queue must not brick the screen; losing unsent taps is bad,
    // but a tailor who cannot open her station at all is worse.
    return []
  }
}

const write = (rows: QueuedMovement[]) => {
  try {
    localStorage.setItem(KEY, JSON.stringify(rows))
  } catch {
    /* storage full or blocked — the in-flight request still carries the move */
  }
  notify()
}

// --- subscription so the header badge tracks the queue without polling -------

type Listener = (count: number) => void
const listeners = new Set<Listener>()

const notify = () => {
  const n = read().length
  listeners.forEach((fn) => fn(n))
}

export const subscribe = (fn: Listener): (() => void) => {
  listeners.add(fn)
  fn(read().length)
  return () => listeners.delete(fn)
}

// --- queue operations --------------------------------------------------------

export const pending = (): QueuedMovement[] =>
  read().sort((a, b) => a.queuedAt - b.queuedAt)

export const pendingCount = (): number => read().length

/** Device-generated ref. `crypto.randomUUID` where available, RFC-4122 v4 otherwise. */
export const newClientRef = (): string => {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
    return crypto.randomUUID()
  }

  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0
    const v = c === 'x' ? r : (r & 0x3) | 0x8
    return v.toString(16)
  })
}

/** Record the intent. Always call this before the request goes out. */
export const enqueue = (
  movement: Omit<QueuedMovement, 'queuedAt' | 'attempts' | 'clientRef'> &
    Partial<Pick<QueuedMovement, 'clientRef'>>,
): QueuedMovement => {
  const row: QueuedMovement = {
    clientRef: movement.clientRef ?? newClientRef(),
    queuedAt: Date.now(),
    attempts: 0,
    ...movement,
  } as QueuedMovement

  write([...read(), row])
  return row
}

/** The movement landed (or was already there). Stop tracking it. */
export const settle = (clientRef: string) => {
  write(read().filter((m) => m.clientRef !== clientRef))
}

/**
 * Undo while still queued — the tap never reached the server, so removing the
 * row *is* the undo. No round trip, and nothing for a supervisor to reverse.
 * Returns false when the movement has already gone, in which case the caller
 * has to post a REVERSAL instead.
 */
export const withdraw = (clientRef: string): boolean => {
  const rows = read()
  const next = rows.filter((m) => m.clientRef !== clientRef)

  if (next.length === rows.length) return false

  write(next)
  return true
}

export const isQueued = (clientRef: string): boolean =>
  read().some((m) => m.clientRef === clientRef)

/** A send failed for a reason worth retrying. */
export const recordAttempt = (clientRef: string): number => {
  const rows = read()
  const row = rows.find((m) => m.clientRef === clientRef)
  if (!row) return 0

  row.attempts += 1
  write(rows)
  return row.attempts
}

export const exhausted = (m: QueuedMovement): boolean => m.attempts >= MAX_ATTEMPTS

export const clear = () => write([])

/**
 * Drain the queue in the order the taps happened.
 *
 * At-least-once by design: the server deduplicates, so sending twice is safe
 * and losing a tap is not. A movement the server refuses outright (a 4xx that
 * is not a transport problem) is settled rather than retried — the pieces are
 * genuinely not there, and spinning on it would block every later tap behind
 * it.
 */
export async function drain(
  send: (m: QueuedMovement) => Promise<{ ok: true } | { ok: false; retry: boolean }>,
): Promise<{ sent: number; failed: number; parked: number }> {
  let sent = 0
  let failed = 0
  let parked = 0

  for (const movement of pending()) {
    const result = await send(movement)

    if (result.ok) {
      settle(movement.clientRef)
      sent += 1
      continue
    }

    if (!result.retry) {
      settle(movement.clientRef)
      failed += 1
      continue
    }

    const attempts = recordAttempt(movement.clientRef)

    if (attempts >= MAX_ATTEMPTS) {
      parked += 1
      continue
    }

    // Order matters on a production line: a later movement may depend on this
    // one having landed, so stop rather than skipping ahead.
    break
  }

  return { sent, failed, parked }
}

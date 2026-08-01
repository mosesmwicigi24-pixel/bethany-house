/**
 * tailorCopy.ts
 *
 * Every string the station screen says, in both languages it says it in.
 *
 * Sentence case, active voice, and the verb on the button is the verb in the
 * confirmation — "Move to Button fixing" is confirmed by "4 moved to Button
 * fixing", never by "Success". A worker whose hands are back on the machine
 * should be able to confirm what happened from the shape of the sentence.
 *
 * Kiswahili here is a first draft and is marked as such deliberately: these
 * strings must be reviewed by someone who sews before the pilot, not by a
 * developer and not by a machine. The interpolation slots are the same in both
 * languages so a reviewer can replace a line without touching code.
 *
 * Reason labels are NOT here — they come from `movement_reasons`, which carries
 * its own `label_sw`, because a supervisor can add a reason without shipping an
 * app and a translation that lives in the bundle would immediately be stale.
 */

export type Lang = 'en' | 'sw'

type Vars = Record<string, string | number>

const fill = (template: string, vars?: Vars) =>
  vars
    ? Object.entries(vars).reduce(
        (s, [k, v]) => s.replaceAll(`{${k}}`, String(v)),
        template,
      )
    : template

const EN = {
  'station.waiting': 'waiting for you',
  'station.movedToday': 'moved today',
  'station.thisHour': 'this hour',
  'station.target': 'target',

  'group.rework': 'Sent back from QC',
  'group.ready': 'Ready for you',
  'group.held': 'On hold',
  'group.upstream': 'Coming your way',

  'move.question': 'How many are you moving?',
  'move.all': 'All {n}',
  'move.custom': 'Other',
  'move.max': 'most you can move is {n}',
  'move.action': 'Move to {stage} · {n}',
  'move.actionIdle': 'Move to {stage}',
  'move.done': 'Moved {n} to {stage}',
  'move.undo': 'Undo',
  'move.conflict': '{stage} now has {n}. Someone moved these before you.',

  'hold.title': 'What is stopping the work?',
  'hold.count': 'How many pieces are stopped?',
  'hold.action': 'Put on hold and tell the supervisor',
  'hold.release': 'Work can continue',
  'hold.released': 'Released {n}',

  'qc.pass': 'Pass to {stage}',
  'qc.reject': 'Send back',
  'qc.reason': 'What is wrong with them?',
  'qc.target': 'Send them back to',
  'qc.sent': 'Sent {n} back to {stage}',

  'job.details': 'Job details',
  'job.problem': 'Report a problem',
  'job.spec': 'What you are making',
  'job.checklist': 'Check before you move these on',
  'job.handover': 'Note from {stage} · {name}',
  'job.history': 'Recent movements',
  'job.close': 'Close',
  'job.cancel': 'Cancel',
  'job.due': 'due {date}',
  'job.today': 'due today',
  'job.tomorrow': 'due tomorrow',
  'job.overdue': 'overdue',

  'empty.title': 'Nothing at your station yet',
  'empty.pull': '{stage} is holding {n} — they reach you next.',
  'empty.quiet': 'Nothing is coming your way right now.',

  'sync.offline': 'Offline — your taps are saved',
  'sync.queued': '{n} waiting to send',
  'sync.ok': 'All sent',
}

/**
 * First-draft Kiswahili. Reviewed by a tailor before the pilot, not shipped as
 * machine output. Missing keys fall through to English rather than rendering an
 * empty label — a blank button is worse than an English one.
 */
const SW: Partial<Record<keyof typeof EN, string>> = {
  'station.waiting': 'zinakusubiri',
  'station.movedToday': 'zimehamishwa leo',
  'station.thisHour': 'saa hii',
  'station.target': 'lengo',

  'group.rework': 'Zimerudishwa kutoka Ukaguzi',
  'group.ready': 'Tayari kwako',
  'group.held': 'Zimesimamishwa',
  'group.upstream': 'Zinakuja kwako',

  'move.question': 'Unahamisha ngapi?',
  'move.all': 'Zote {n}',
  'move.custom': 'Nyingine',
  'move.max': 'unaweza kuhamisha {n} pekee',
  'move.action': 'Peleka {stage} · {n}',
  'move.actionIdle': 'Peleka {stage}',
  'move.done': '{n} zimehamishwa {stage}',
  'move.undo': 'Tengua',
  'move.conflict': '{stage} sasa ina {n}. Mtu amehamisha kabla yako.',

  'hold.title': 'Nini kinazuia kazi?',
  'hold.count': 'Ni vipande vingapi vimesimama?',
  'hold.action': 'Simamisha na mjulishe msimamizi',
  'hold.release': 'Kazi inaweza kuendelea',
  'hold.released': 'Zimeachiliwa {n}',

  'qc.pass': 'Pitisha {stage}',
  'qc.reject': 'Rudisha',
  'qc.reason': 'Tatizo ni nini?',
  'qc.target': 'Zirudishwe wapi',
  'qc.sent': '{n} zimerudishwa {stage}',

  'job.details': 'Maelezo ya kazi',
  'job.problem': 'Ripoti tatizo',
  'job.spec': 'Unachotengeneza',
  'job.checklist': 'Kagua kabla ya kupeleka mbele',
  'job.handover': 'Ujumbe kutoka {stage} · {name}',
  'job.history': 'Uhamisho wa hivi karibuni',
  'job.close': 'Funga',
  'job.cancel': 'Ghairi',
  'job.due': 'mwisho {date}',
  'job.today': 'mwisho leo',
  'job.tomorrow': 'mwisho kesho',
  'job.overdue': 'imechelewa',

  'empty.title': 'Hakuna kazi kwako bado',
  'empty.pull': '{stage} ina {n} — zitakufikia baadaye.',
  'empty.quiet': 'Hakuna kinachokuja kwako sasa.',

  'sync.offline': 'Nje ya mtandao — umebofya kumehifadhiwa',
  'sync.queued': '{n} zinasubiri kutumwa',
  'sync.ok': 'Zote zimetumwa',
}

export type CopyKey = keyof typeof EN

export function makeCopy(lang: Lang) {
  return (key: CopyKey, vars?: Vars): string =>
    fill((lang === 'sw' ? SW[key] : undefined) ?? EN[key], vars)
}

export type Copy = ReturnType<typeof makeCopy>

/** Reason labels live in data; pick the right column for the reader. */
export const reasonLabel = (
  reason: { label?: string | null; label_sw?: string | null } | null | undefined,
  lang: Lang,
): string | null =>
  (lang === 'sw' ? reason?.label_sw : undefined) ?? reason?.label ?? null

/**
 * Due dates read as urgency, not as a calendar. "due tomorrow" is actionable;
 * "2026-08-07" has to be decoded while holding a garment.
 */
export function dueLabel(daysLeft: number | null, dueAt: string | null, t: Copy): string | null {
  if (daysLeft === null) return null
  if (daysLeft < 0) return t('job.overdue')
  if (daysLeft === 0) return t('job.today')
  if (daysLeft === 1) return t('job.tomorrow')

  const date = dueAt
    ? new Date(dueAt).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })
    : `${daysLeft}d`

  return t('job.due', { date })
}

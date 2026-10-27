<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Automations (People C2): the alerts the hub sends the manager without
 * anyone asking, each with a switch, a threshold and the people who hear it.
 * One table row per alert (`automations`), seeded by the C2 migration; the
 * Setup › Automations page edits it. The jobs and the clock read it here.
 *
 *   missed_shift       no punch N minutes after a published shift starts
 *   outside_fence      a clock-in from outside the outlet's zone (override)
 *   overtime_soon      N minutes before today's regular hours run out
 *   document_expiring  a staff document expires within N days
 *
 * recipients: managers (AttendanceAlerts::managersFor — the person's
 * reports_to and attendance.manage in their outlets), hr (hr.edit holders)
 * or owner (super_admin); extra_user_ids adds named people. The subject of
 * an alert never receives it.
 */
class Automations
{
    public const KEYS = [
        'missed_shift'      => ['label' => 'Missed shift',      'unit' => 'minutes', 'min' => 5,  'max' => 240, 'text' => 'No punch %d minutes after a published shift starts.'],
        'outside_fence'     => ['label' => 'Outside the zone',  'unit' => null,      'min' => 0,  'max' => 0,   'text' => 'Someone clocks in from outside the outlet\'s clock-in zone.'],
        'overtime_soon'     => ['label' => 'Overtime soon',     'unit' => 'minutes', 'min' => 5,  'max' => 240, 'text' => 'Someone is %d minutes from their regular hours for the day.'],
        'document_expiring' => ['label' => 'Document expiring', 'unit' => 'days',    'min' => 1,  'max' => 365, 'text' => 'A staff document expires within %d days (checked each morning).'],
        // C3: not an alert — a post the hub writes itself. No recipients.
        'celebrations'      => ['label' => 'Celebrations',      'unit' => null,      'min' => 0,  'max' => 0,   'text' => 'Birthdays and work anniversaries from the employee file are posted on the Updates feed each morning.', 'recipients' => false],
    ];

    public const RECIPIENTS = ['managers', 'hr', 'owner'];

    /** Every automation, in KEYS order, with its row (defaults when the row is missing). */
    public static function all(): array
    {
        $rows = DB::table('automations')->get()->keyBy('key');
        $out = [];
        foreach (self::KEYS as $key => $meta) {
            $r = $rows->get($key);
            $out[$key] = [
                'key'            => $key,
                'label'          => $meta['label'],
                'unit'           => $meta['unit'],
                'min'            => $meta['min'],
                'max'            => $meta['max'],
                'enabled'        => $r ? (bool) $r->enabled : false,
                'threshold'      => $r ? (int) $r->threshold : 0,
                'recipients'     => $r ? (string) $r->recipients : 'managers',
                'extra_user_ids' => $r && $r->extra_user_ids ? array_values(array_map('intval', json_decode($r->extra_user_ids, true) ?: [])) : [],
                'has_recipients' => $meta['recipients'] ?? true,
                'text'           => $meta['unit'] ? sprintf($meta['text'], $r ? (int) $r->threshold : 0) : $meta['text'],
            ];
        }
        return $out;
    }

    public static function get(string $key): array
    {
        return self::all()[$key] ?? ['enabled' => false, 'threshold' => 0, 'recipients' => 'managers', 'extra_user_ids' => []];
    }

    /** On, with its threshold; null when switched off (or the row is gone). */
    public static function active(string $key): ?array
    {
        $a = self::get($key);
        return $a['enabled'] ? $a : null;
    }

    public static function save(string $key, array $values, ?int $actorId = null): void
    {
        if (!isset(self::KEYS[$key])) return;
        $row = array_intersect_key($values, array_flip(['enabled', 'threshold', 'recipients', 'extra_user_ids']));
        if (array_key_exists('extra_user_ids', $row)) $row['extra_user_ids'] = json_encode(array_values(array_unique(array_map('intval', $row['extra_user_ids'] ?? []))));
        if (array_key_exists('enabled', $row)) $row['enabled'] = (bool) $row['enabled'];
        $row['updated_by'] = $actorId;
        $row['updated_at'] = now();
        if (DB::table('automations')->where('key', $key)->exists()) DB::table('automations')->where('key', $key)->update($row);
        else DB::table('automations')->insert($row + ['key' => $key, 'created_at' => now()]);
    }

    /** Who hears this alert about $subject: active users, the subject excluded, nobody twice. */
    public static function recipientsFor(string $key, ?User $subject = null): Collection
    {
        $a = self::get($key);
        $users = match ($a['recipients']) {
            'hr'    => User::where('status', 'active')->where('user_type', 'staff')->get()->filter(fn (User $u) => $u->can('hr.edit')),
            'owner' => User::where('status', 'active')->get()->filter(fn (User $u) => $u->hasRole('super_admin')),
            default => $subject ? AttendanceAlerts::managersFor($subject) : collect(),
        };
        if ($a['extra_user_ids']) {
            $users = $users->concat(User::whereIn('id', $a['extra_user_ids'])->where('status', 'active')->get());
        }
        return $users->unique('id')->reject(fn (User $u) => $subject && $u->id === $subject->id)->values();
    }
}

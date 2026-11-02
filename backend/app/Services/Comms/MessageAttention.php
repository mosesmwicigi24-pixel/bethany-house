<?php

namespace App\Services\Comms;

use App\Models\ChannelMessage;
use App\Models\User;
use App\Services\ContextChannels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What in a person's conversations still needs them (owner, 2026-11-02:
 * "every important message must be noticed, and the right person must be
 * able to respond quickly").
 *
 * Derived at read time from three per-member pointers — nothing is stored per
 * message, so the answer can always be rebuilt from the messages themselves:
 *
 *   unread    messages from others after last_read_message_id
 *   notice    the newest of those, while it is also past the point the
 *             floating notice was dismissed to (notice_dismissed_message_id).
 *             Dismissing a notice never reads the message. Muted conversations
 *             give no notice; their messages still count as unread.
 *   pending   messages from others that ask something of THIS person — every
 *             message in a direct conversation, an @mention anywhere else —
 *             that came after their own latest message in the conversation
 *             (a reply answers everything before it) and after the point they
 *             marked it handled (acknowledged_message_id).
 *
 * A pending message turns "warn" (amber) and then "escalated" (red) after the
 * minutes set in messaging.response_warn_minutes / _escalate_minutes.
 *
 * Membership is the gate, as for every conversation endpoint; a production
 * order thread is left out once its order is no longer visible to the person.
 */
final class MessageAttention
{
    public const WARN_DEFAULT     = 60;
    public const ESCALATE_DEFAULT = 240;

    /** @return array{warn_minutes:int, escalate_minutes:int} */
    public static function thresholds(): array
    {
        $rows = DB::table('settings')
            ->whereIn('key', ['messaging.response_warn_minutes', 'messaging.response_escalate_minutes'])
            ->pluck('value', 'key');
        $warn     = max(1, (int) ($rows['messaging.response_warn_minutes'] ?? self::WARN_DEFAULT));
        $escalate = max($warn, (int) ($rows['messaging.response_escalate_minutes'] ?? self::ESCALATE_DEFAULT));

        return ['warn_minutes' => $warn, 'escalate_minutes' => $escalate];
    }

    /**
     * @return array{channels: list<array<string,mixed>>, totals: array<string,int>, thresholds: array<string,int>, server_time: string}
     */
    public static function forUser(User $user, ?int $onlyChannelId = null): array
    {
        $uid        = (int) $user->id;
        $thresholds = self::thresholds();
        $now        = now();

        $memberships = DB::table('channel_members as cm')
            ->join('channels as c', 'c.id', '=', 'cm.channel_id')
            ->whereNull('c.deleted_at')
            ->where('cm.user_id', $uid)
            ->when($onlyChannelId, fn ($q) => $q->where('c.id', $onlyChannelId))
            ->get([
                'c.id', 'c.type', 'c.name', 'c.context_type', 'c.context_id', 'c.last_message_id',
                'cm.last_read_message_id', 'cm.notice_dismissed_message_id', 'cm.acknowledged_message_id', 'cm.muted_until',
            ]);

        $visibleOrders = ContextChannels::visibleProductionOrderIds(
            $user,
            $memberships->where('context_type', 'production_order')->pluck('context_id')->filter()->map(fn ($id) => (int) $id)->all(),
        );
        $memberships = $memberships->reject(fn ($m) => $m->context_type === 'production_order'
            && ! in_array((int) $m->context_id, $visibleOrders, true));

        $ids = $memberships->pluck('id')->map(fn ($id) => (int) $id)->all();
        $myLast = $ids === [] ? collect() : DB::table('channel_messages')
            ->whereIn('channel_id', $ids)->where('user_id', $uid)->whereNull('deleted_at')
            ->groupBy('channel_id')->selectRaw('channel_id, MAX(id) AS id')->pluck('id', 'channel_id');

        $rows = [];
        foreach ($memberships as $m) {
            $lastRead    = (int) ($m->last_read_message_id ?? 0);
            $pendingFrom = max((int) ($myLast[$m->id] ?? 0), (int) ($m->acknowledged_message_id ?? 0));
            $newest      = (int) ($m->last_message_id ?? 0);
            if ($newest <= $lastRead && $newest <= $pendingFrom) {
                continue;                                   // nothing new since either pointer
            }

            $fromOthers = fn () => DB::table('channel_messages')
                ->where('channel_id', $m->id)->whereNull('deleted_at')
                ->whereNotNull('user_id')->where('user_id', '!=', $uid);

            $unread = $fromOthers()->where('id', '>', $lastRead)->count();

            $pendingQ = $fromOthers()->where('id', '>', $pendingFrom)
                ->when($m->type !== 'dm', fn ($q) => $q->whereJsonContains('mentions', $uid));
            $pendingCount = (clone $pendingQ)->count();
            $pendingSince = $pendingCount > 0 ? (clone $pendingQ)->min('created_at') : null;

            if ($unread === 0 && $pendingCount === 0) {
                continue;
            }

            $muted       = $m->muted_until && Carbon::parse($m->muted_until)->isFuture();
            $noticeFrom  = max($lastRead, (int) ($m->notice_dismissed_message_id ?? 0));
            $latest      = $unread > 0 && ! $muted
                ? $fromOthers()->where('id', '>', $noticeFrom)->orderByDesc('id')->first(['id', 'user_id', 'body', 'mentions', 'created_at'])
                : null;
            $noticeCount = $latest ? $fromOthers()->where('id', '>', $noticeFrom)->count() : 0;

            $level = null;
            if ($pendingSince) {
                $waited = Carbon::parse($pendingSince)->diffInMinutes($now);
                $level  = $waited >= $thresholds['escalate_minutes'] ? 'escalated' : ($waited >= $thresholds['warn_minutes'] ? 'warn' : 'waiting');
            }

            $rows[] = [
                'channel_id'    => (int) $m->id,
                'type'          => $m->type,
                'name'          => $m->name,
                'context_type'  => $m->context_type,
                'muted'         => (bool) $muted,
                'unread_count'  => $unread,
                'pending_count' => $pendingCount,
                'pending_since' => $pendingSince ? Carbon::parse($pendingSince)->toIso8601String() : null,
                'level'         => $level,
                'notice'        => $latest ? [
                    'message_id'   => (int) $latest->id,
                    'user_id'      => (int) $latest->user_id,
                    'body'         => ChannelMessage::plainPreview($latest->body, 160),
                    'mentions_me'  => in_array($uid, array_map('intval', json_decode((string) $latest->mentions, true) ?: []), true),
                    'created_at'   => Carbon::parse($latest->created_at)->toIso8601String(),
                    'count'        => $noticeCount,
                ] : null,
            ];
        }

        // Names: the other person for a direct conversation, the sender of each notice.
        $userIds = collect($rows)->pluck('notice.user_id')->filter()->all();
        $dmIds   = collect($rows)->where('type', 'dm')->pluck('channel_id')->all();
        $dmOther = $dmIds === [] ? collect() : DB::table('channel_members')
            ->whereIn('channel_id', $dmIds)->where('user_id', '!=', $uid)->pluck('user_id', 'channel_id');
        $names = DB::table('users')->whereIn('id', array_unique(array_merge($userIds, $dmOther->values()->all())))
            ->get(['id', 'first_name', 'last_name'])->keyBy('id');
        $person = fn ($id) => ($u = $names[(int) $id] ?? null) ? [
            'id'       => (int) $u->id,
            'name'     => trim("{$u->first_name} {$u->last_name}"),
            'initials' => strtoupper(mb_substr((string) $u->first_name, 0, 1) . mb_substr((string) $u->last_name, 0, 1)),
        ] : null;

        foreach ($rows as &$row) {
            if ($row['type'] === 'dm') {
                $row['name'] = $person($dmOther[$row['channel_id']] ?? 0)['name'] ?? 'Direct message';
            }
            if ($row['notice']) {
                $row['notice']['user'] = $person($row['notice']['user_id']);
                unset($row['notice']['user_id']);
            }
        }
        unset($row);

        // Waiting longest first, then newest notice.
        usort($rows, fn ($a, $b) => [$b['pending_count'] > 0, $b['notice']['created_at'] ?? ''] <=> [$a['pending_count'] > 0, $a['notice']['created_at'] ?? '']);

        $c = collect($rows);

        return [
            'channels'    => $rows,
            'totals'      => [
                'unread'    => (int) $c->sum('unread_count'),
                'pending'   => (int) $c->sum('pending_count'),
                'warn'      => $c->where('level', 'warn')->count(),
                'escalated' => $c->where('level', 'escalated')->count(),
                'notices'   => $c->whereNotNull('notice')->count(),
            ],
            'thresholds'  => $thresholds,
            'server_time' => $now->toIso8601String(),
        ];
    }
}

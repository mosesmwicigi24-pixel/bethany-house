<?php

namespace App\Services\Comms;

use App\Models\Channel;
use App\Models\ChannelMessage;
use App\Models\Order;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Services\ActivityLogService;

/**
 * The Messages "Assist" rail (owner's redesign, 22 Oct 2026, PR 3).
 *
 * Four things a person can ask of a thread, each one tap, each answered by
 * the model with the thread and — for order threads — the live order in
 * front of it:
 *   summarise  what was agreed, what is still open, who owes what
 *   translate  the draft (or a message) between English and Kiswahili
 *   draft      the message to the customer about this order, or a reply
 *              to the thread, in the hub's own voice
 *   replies    three short replies to pick from
 *
 * Nothing runs on its own. Every call writes an audit row with the action,
 * the channel and the sizes — never the text — and the text is never stored.
 * Entity tokens (@[Name](user:1), #[PO-1](entity:order:1)) are flattened to
 * their labels before anything leaves the hub.
 */
class Assist
{
    public const ACTIONS   = ['summarise', 'translate', 'draft', 'replies'];
    public const LANGUAGES = ['en' => 'English', 'sw' => 'Kiswahili'];

    private const VOICE = 'You write for Bethany House, a clothing and tailoring business in Nairobi, Kenya. '
        . 'Plain, warm, exact. Short sentences. Kenyan English or natural Kiswahili as asked, never a mix unless the thread itself mixes. '
        . 'Use the order numbers, names, dates and amounts exactly as given; invent nothing. Amounts stay in their stated currency. '
        . 'No headings, no markdown, no preamble: answer with the text only.';

    public function __construct(private AssistModel $model)
    {
    }

    public function configured(): bool
    {
        return $this->model->configured();
    }

    /** @return array{text:string} */
    public function summarise(Channel $channel, User $by): array
    {
        $thread = $this->thread($channel);
        if ($thread === '') {
            throw new AssistUnavailable('There is nothing in this thread to summarise yet.');
        }
        $system = self::VOICE . "\nSummarise a staff conversation for someone who has not read it. "
            . 'Three to six short lines: what was agreed, what is still open, and who is to do what by when. '
            . 'Then one line beginning "Next:" with the single most useful next action.';
        $text = $this->model->complete($system, $this->context($channel) . "CONVERSATION:\n" . $thread, 1200);

        return $this->done('summarise', $channel, $by, $thread, $text);
    }

    /** @return array{text:string} */
    public function translate(Channel $channel, User $by, string $text, string $to): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new AssistUnavailable('Type the message first, then ask for the translation.');
        }
        $lang   = self::LANGUAGES[$to] ?? 'English';
        $system = self::VOICE . "\nTranslate the message into natural {$lang} as a Kenyan colleague would write it. "
            . 'Keep names, order numbers, amounts and dates exactly. Answer with the translation only.';
        $out = $this->model->complete($system, $this->flatten($text), 1500);

        return $this->done('translate', $channel, $by, $text, $out, ['to' => $to]);
    }

    /** @return array{text:string} */
    public function draft(Channel $channel, User $by, string $audience, string $language): array
    {
        $lang   = self::LANGUAGES[$language] ?? 'English';
        $thread = $this->thread($channel);
        if ($audience === 'customer') {
            $order = $this->order($channel);
            if ($order === null) {
                throw new AssistUnavailable('A customer message is drafted from an order thread. Open the order\'s thread and try again.');
            }
            $system = self::VOICE . "\nDraft a WhatsApp message from Bethany House to the customer named in the order, in {$lang}, "
                . 'telling them what the conversation has settled about their order (readiness, fitting or collection time, balance due). '
                . 'Two to five sentences, one greeting with the customer\'s first name, one clear ask or next step, sign off as Bethany House. '
                . 'Say nothing the conversation does not support.';
        } else {
            $system = self::VOICE . "\nDraft {$by->first_name}'s next reply in this staff conversation, in {$lang}: "
                . 'answer what was asked of them, confirm what they can confirm, and ask for the one thing still missing. Two to four sentences.';
        }
        $text = $this->model->complete($system, $this->context($channel) . "CONVERSATION:\n" . ($thread ?: '(no messages yet)'), 1200);

        return $this->done('draft', $channel, $by, $thread, $text, ['audience' => $audience, 'language' => $language]);
    }

    /** @return array{text:string, options:list<string>} */
    public function replies(Channel $channel, User $by): array
    {
        $thread = $this->thread($channel, 20);
        if ($thread === '') {
            throw new AssistUnavailable('There is nothing to reply to yet.');
        }
        $system = self::VOICE . "\nOffer three short replies {$by->first_name} could send next in this staff conversation, in the language the last message used. "
            . 'Each under 15 words; one confirms, one asks the open question, one proposes the next step. One per line, no numbering, no quotes.';
        $text    = $this->model->complete($system, $this->context($channel) . "CONVERSATION:\n" . $thread, 400);
        $options = array_values(array_filter(array_map(fn ($l) => trim(preg_replace('/^[\-\d\.\)\s"“”]+|["“”]+$/u', '', $l)), explode("\n", $text))));

        return $this->done('replies', $channel, $by, $thread, $text) + ['options' => array_slice($options, 0, 3)];
    }

    // ── what the model sees ──────────────────────────────────────────────────

    /** The newest messages, oldest first, as "Name (14:05): text" lines, entity tokens flattened. */
    private function thread(Channel $channel, ?int $limit = null): string
    {
        $limit = $limit ?? (int) config('assist.max_messages', 80);
        $rows  = ChannelMessage::with('user:id,first_name,last_name')
            ->where('channel_id', $channel->id)
            ->where('type', 'text')
            ->orderByDesc('id')->limit($limit)->get()->reverse();

        return $rows->map(function (ChannelMessage $m) {
            $who  = $m->user ? trim($m->user->first_name . ' ' . $m->user->last_name) : 'Someone';
            $when = $m->created_at?->timezone(config('app.timezone'))->format('D d M H:i');
            $body = $this->flatten((string) $m->body);
            if ($body === '' && !empty($m->attachments)) {
                $body = '[sent an attachment]';
            }

            return "{$who} ({$when}): {$body}";
        })->filter(fn ($l) => !str_ends_with($l, ': '))->implode("\n");
    }

    /** Order facts for an order thread, or an empty string. */
    private function context(Channel $channel): string
    {
        $o = $this->order($channel);
        if ($o === null) {
            return "THREAD: {$channel->name}\n\n";
        }
        $lines = ["ORDER {$o['number']} ({$o['kind']})"];
        foreach (['customer', 'phone', 'item', 'status', 'stage', 'due', 'total', 'paid'] as $k) {
            if (!empty($o[$k])) {
                $lines[] = ucfirst($k) . ": {$o[$k]}";
            }
        }

        return implode("\n", $lines) . "\n\n";
    }

    /** @return array<string, string|null>|null */
    private function order(Channel $channel): ?array
    {
        if ($channel->context_type === 'production_order' && $channel->context_id) {
            $p = ProductionOrder::with('customerOrder')->find($channel->context_id);
            if (!$p) {
                return null;
            }
            $co = $p->customerOrder;

            return [
                'kind'     => 'production order',
                'number'   => (string) $p->order_number,
                'customer' => $co?->customer_name ?: null,
                'phone'    => $co?->customer_phone ?: null,
                'item'     => trim(($p->product_name ?? '') . ($p->quantity ? " × {$p->quantity}" : '')) ?: null,
                'status'   => $p->status ? str_replace('_', ' ', $p->status) : null,
                'stage'    => $p->current_stage ?: null,
                'due'      => $p->due_date?->format('D d M Y'),
                'total'    => $co && $co->total_amount !== null ? trim(($co->currency_code ?: 'KES') . ' ' . number_format((float) $co->total_amount, 2)) : null,
                'paid'     => $co?->payment_status ? str_replace('_', ' ', $co->payment_status) : null,
            ];
        }
        if ($channel->context_type === 'order' && $channel->context_id) {
            $o = Order::withoutViewerScope()->find($channel->context_id);
            if (!$o) {
                return null;
            }

            return [
                'kind'     => 'sales order',
                'number'   => (string) $o->order_number,
                'customer' => $o->customer_name ?: null,
                'phone'    => $o->customer_phone ?: null,
                'item'     => null,
                'status'   => $o->status ? str_replace('_', ' ', $o->status) : null,
                'stage'    => null,
                'due'      => null,
                'total'    => $o->total_amount !== null ? trim(($o->currency_code ?: 'KES') . ' ' . number_format((float) $o->total_amount, 2)) : null,
                'paid'     => $o->payment_status ? str_replace('_', ' ', $o->payment_status) : null,
            ];
        }

        return null;
    }

    /** `@[Jane](user:3)` → `@Jane`; `#[PO-1](entity:order:9)` → `PO-1`; attachment markup dropped. */
    private function flatten(string $body): string
    {
        $t = preg_replace('/@\[([^\]]+)\]\(user:\d+\)/', '@$1', $body);
        $t = preg_replace('/#\[([^\]]+)\]\(entity:[^)]+\)/', '$1', $t);
        $t = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $t);          // images
        $t = preg_replace('/\[[^\]]*\]\((?:https?:)?\/\/[^)]*\)/', '', $t); // file links

        return trim(preg_replace('/[ \t]+/', ' ', $t));
    }

    /** @return array{text:string} */
    private function done(string $action, Channel $channel, User $by, string $in, string $out, array $extra = []): array
    {
        ActivityLogService::log('assist_used', $channel, [
            'action'     => $action,
            'channel_id' => $channel->id,
            'chars_in'   => strlen($in),
            'chars_out'  => strlen($out),
        ] + $extra, "Assist: {$action} on {$channel->name}", $by);

        return ['text' => $out];
    }
}

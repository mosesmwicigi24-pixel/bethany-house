<?php

namespace Tests\Feature;

use App\Events\MessageAttentionChanged;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Message accountability (owner, 2026-11-02): every important message is
 * noticed, and the right person can answer it quickly.
 *
 * GET /channels/attention answers, per person: what is unread, which
 * conversations still owe them a floating notice, and what is waiting on
 * their reply. Each test below is a real situation on the shop floor, driven
 * through the same endpoints the console uses.
 */
class MessageAttentionTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $first): User
    {
        return User::factory()->create(['first_name' => $first, 'last_name' => 'Test']);
    }

    private function conversation(string $type, User ...$members): Channel
    {
        $channel = Channel::create(['type' => $type, 'name' => $type === 'dm' ? null : 'Restocking', 'is_private' => true]);
        foreach ($members as $m) {
            DB::table('channel_members')->insert([
                'channel_id' => $channel->id, 'user_id' => $m->id, 'role' => 'member',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $channel;
    }

    private function say(User $who, Channel $channel, string $body): int
    {
        Sanctum::actingAs($who);

        return (int) $this->postJson("/api/v1/admin/channels/{$channel->id}/messages", ['body' => $body])
            ->assertCreated()->json('message.id');
    }

    /** @return array<string,mixed> */
    private function attention(User $who): array
    {
        Sanctum::actingAs($who);

        return $this->getJson('/api/v1/admin/channels/attention')->assertOk()->json();
    }

    private function row(User $who, Channel $channel): ?array
    {
        return collect($this->attention($who)['channels'])->firstWhere('channel_id', $channel->id);
    }

    private function mention(User $u): string
    {
        return "@[{$u->first_name} {$u->last_name}](user:{$u->id})";
    }

    // 1 ─────────────────────────────────────────────────────────────────────
    public function test_a_direct_message_is_unread_noticed_and_waiting_on_the_recipient_only(): void
    {
        [$priscilla, $moses] = [$this->staff('Priscilla'), $this->staff('Moses')];
        $dm = $this->conversation('dm', $priscilla, $moses);

        $id = $this->say($priscilla, $dm, 'We are out of plastic cups and large chalices');

        $row = $this->row($moses, $dm);
        $this->assertSame(1, $row['unread_count']);
        $this->assertSame(1, $row['pending_count']);
        $this->assertSame('Priscilla Test', $row['name'], 'a direct conversation is named after the other person');
        $this->assertSame($id, $row['notice']['message_id']);
        $this->assertSame('Priscilla Test', $row['notice']['user']['name']);
        $this->assertSame('PT', $row['notice']['user']['initials']);
        $this->assertSame('We are out of plastic cups and large chalices', $row['notice']['body']);
        $this->assertSame('waiting', $row['level']);

        $this->assertSame([], $this->attention($priscilla)['channels'], 'your own message needs nothing from you');
    }

    // 2 ─────────────────────────────────────────────────────────────────────
    public function test_simultaneous_messages_give_one_notice_per_conversation_not_one_per_message(): void
    {
        [$a, $b, $me] = [$this->staff('Priscilla'), $this->staff('Everlyne'), $this->staff('Moses')];
        $dmA = $this->conversation('dm', $a, $me);
        $dmB = $this->conversation('dm', $b, $me);

        $this->say($a, $dmA, 'Packaging bags');
        $this->say($a, $dmA, '1500 ksh transaction fee');
        $last = $this->say($a, $dmA, 'and 157 ksh wrong delivery');
        $this->say($b, $dmB, 'Chalices need 10 days');

        $att = $this->attention($me);
        $this->assertSame(2, $att['totals']['notices']);
        $this->assertSame(4, $att['totals']['unread']);
        $rowA = collect($att['channels'])->firstWhere('channel_id', $dmA->id);
        $this->assertSame($last, $rowA['notice']['message_id'], 'the card shows the newest message');
        $this->assertSame(3, $rowA['notice']['count']);
    }

    // 3 ─────────────────────────────────────────────────────────────────────
    public function test_group_chatter_is_noticed_but_waits_on_nobody_until_someone_is_mentioned(): void
    {
        [$priscilla, $moses, $isaac] = [$this->staff('Priscilla'), $this->staff('Moses'), $this->staff('Isaac')];
        $space = $this->conversation('space', $priscilla, $moses, $isaac);

        $this->say($priscilla, $space, 'Restocking list is up');
        $row = $this->row($moses, $space);
        $this->assertSame(1, $row['unread_count']);
        $this->assertNotNull($row['notice']);
        $this->assertSame(0, $row['pending_count']);
        $this->assertNull($row['level']);

        $this->say($priscilla, $space, "{$this->mention($moses)} can you approve the chain order?");
        $this->assertSame(1, $this->row($moses, $space)['pending_count'], 'a mention waits on the person named');
        $this->assertTrue($this->row($moses, $space)['notice']['mentions_me']);
        $this->assertSame(0, $this->row($isaac, $space)['pending_count'], '…and on nobody else');
    }

    // 4 ─────────────────────────────────────────────────────────────────────
    public function test_dismissing_a_notice_hides_it_everywhere_but_does_not_read_or_answer_the_message(): void
    {
        [$priscilla, $moses] = [$this->staff('Priscilla'), $this->staff('Moses')];
        $dm = $this->conversation('dm', $priscilla, $moses);
        $id = $this->say($priscilla, $dm, 'Containers for 200 pieces of bread');

        Event::fake([MessageAttentionChanged::class]);
        Sanctum::actingAs($moses);
        $this->postJson("/api/v1/admin/channels/{$dm->id}/notice-dismiss", ['message_id' => $id])->assertOk();
        Event::assertDispatched(MessageAttentionChanged::class, fn ($e) => $e->userId === $moses->id && $e->reason === 'dismissed');

        // Another tab or device asking afterwards sees the same answer.
        $row = $this->row($moses, $dm);
        $this->assertNull($row['notice']);
        $this->assertSame(1, $row['unread_count'], 'still unread — the badge stays');
        $this->assertSame(1, $row['pending_count'], 'still waiting on a reply');
        $this->assertNull(DB::table('channel_members')->where('channel_id', $dm->id)->where('user_id', $moses->id)->value('last_read_message_id'));
    }

    // 5 ─────────────────────────────────────────────────────────────────────
    public function test_a_newer_message_brings_a_dismissed_notice_back(): void
    {
        [$priscilla, $moses] = [$this->staff('Priscilla'), $this->staff('Moses')];
        $dm = $this->conversation('dm', $priscilla, $moses);
        $this->say($priscilla, $dm, 'First');
        Sanctum::actingAs($moses);
        $this->postJson("/api/v1/admin/channels/{$dm->id}/notice-dismiss")->assertOk();

        $newer = $this->say($priscilla, $dm, 'Noted sir, the chains too');

        $notice = $this->row($moses, $dm)['notice'];
        $this->assertSame($newer, $notice['message_id']);
        $this->assertSame(1, $notice['count'], 'only what came after the dismissal');
    }

    // 6 ─────────────────────────────────────────────────────────────────────
    public function test_replying_answers_reads_and_clears_the_notice_on_every_device(): void
    {
        [$priscilla, $moses] = [$this->staff('Priscilla'), $this->staff('Moses')];
        $dm = $this->conversation('dm', $priscilla, $moses);
        $this->say($priscilla, $dm, 'Which are they?');
        $this->say($priscilla, $dm, 'Packaging bags and delivery');

        Event::fake([MessageAttentionChanged::class]);
        $reply = $this->say($moses, $dm, 'Well noted');
        Event::assertDispatched(MessageAttentionChanged::class, fn ($e) => $e->userId === $moses->id);

        $this->assertNull($this->row($moses, $dm), 'nothing left for Moses');

        // The reply now waits on Priscilla — and per recipient, Moses has responded.
        $this->assertSame(1, $this->row($priscilla, $dm)['pending_count']);
        Sanctum::actingAs($priscilla);
        $reads = collect($this->getJson("/api/v1/admin/channels/{$dm->id}/messages")->assertOk()->json('reads'));
        $this->assertSame($reply, $reads->firstWhere('user_id', $moses->id)['last_posted_message_id']);
    }

    // 7 ─────────────────────────────────────────────────────────────────────
    public function test_reading_without_replying_clears_the_notice_but_the_message_still_waits(): void
    {
        [$priscilla, $moses] = [$this->staff('Priscilla'), $this->staff('Moses')];
        $dm = $this->conversation('dm', $priscilla, $moses);
        $this->say($priscilla, $dm, 'Kindly reverse the cancelled expenses');

        Sanctum::actingAs($moses);
        $this->postJson("/api/v1/admin/channels/{$dm->id}/read")->assertOk();

        $row = $this->row($moses, $dm);
        $this->assertSame(0, $row['unread_count']);
        $this->assertNull($row['notice']);
        $this->assertSame(1, $row['pending_count'], 'seen is not answered');
    }

    // 8 ─────────────────────────────────────────────────────────────────────
    public function test_mark_handled_clears_what_waits_until_the_next_question(): void
    {
        [$priscilla, $moses] = [$this->staff('Priscilla'), $this->staff('Moses')];
        $space = $this->conversation('space', $priscilla, $moses);
        $this->say($priscilla, $space, "{$this->mention($moses)} FYI the cups arrived");

        Sanctum::actingAs($moses);
        $this->postJson("/api/v1/admin/channels/{$space->id}/acknowledge")->assertOk();
        $this->assertNull($this->row($moses, $space), 'handled and read');

        $this->say($priscilla, $space, "{$this->mention($moses)} and the chalices?");
        $this->assertSame(1, $this->row($moses, $space)['pending_count'], 'a new question waits again');
    }

    // 9 ─────────────────────────────────────────────────────────────────────
    public function test_a_message_waiting_too_long_turns_amber_then_red_at_the_set_times(): void
    {
        [$priscilla, $moses] = [$this->staff('Priscilla'), $this->staff('Moses')];
        $dm = $this->conversation('dm', $priscilla, $moses);
        $id = $this->say($priscilla, $dm, 'Phone numbers for the web orders');

        DB::table('channel_messages')->where('id', $id)->update(['created_at' => now()->subMinutes(61)]);
        $this->assertSame('warn', $this->row($moses, $dm)['level']);

        DB::table('channel_messages')->where('id', $id)->update(['created_at' => now()->subMinutes(241)]);
        $att = $this->attention($moses);
        $this->assertSame('escalated', $att['channels'][0]['level']);
        $this->assertSame(1, $att['totals']['escalated']);
        $this->assertSame(['warn_minutes' => 60, 'escalate_minutes' => 240], $att['thresholds']);

        // The times are settings, not code.
        DB::table('settings')->where('key', 'messaging.response_escalate_minutes')->update(['value' => '600']);
        $this->assertSame('warn', $this->row($moses, $dm)['level']);
    }

    // 10 ────────────────────────────────────────────────────────────────────
    public function test_a_muted_conversation_counts_unread_but_raises_no_notice(): void
    {
        [$priscilla, $moses] = [$this->staff('Priscilla'), $this->staff('Moses')];
        $space = $this->conversation('space', $priscilla, $moses);
        DB::table('channel_members')->where('channel_id', $space->id)->where('user_id', $moses->id)
            ->update(['muted_until' => now()->addDay()]);

        $this->say($priscilla, $space, 'Daily stock count');

        $row = $this->row($moses, $space);
        $this->assertSame(1, $row['unread_count']);
        $this->assertTrue($row['muted']);
        $this->assertNull($row['notice']);
    }

    // 11 ────────────────────────────────────────────────────────────────────
    public function test_only_members_see_or_act_on_a_conversation(): void
    {
        [$priscilla, $moses, $outsider] = [$this->staff('Priscilla'), $this->staff('Moses'), $this->staff('Outsider')];
        $dm = $this->conversation('dm', $priscilla, $moses);
        $this->say($priscilla, $dm, 'Private: salary advance');

        $this->assertSame([], $this->attention($outsider)['channels']);
        Sanctum::actingAs($outsider);
        $this->postJson("/api/v1/admin/channels/{$dm->id}/notice-dismiss")->assertForbidden();
        $this->postJson("/api/v1/admin/channels/{$dm->id}/acknowledge")->assertForbidden();

        // Someone removed from a conversation stops being asked to answer it.
        DB::table('channel_members')->where('channel_id', $dm->id)->where('user_id', $moses->id)->delete();
        $this->assertNull($this->row($moses, $dm));
    }

    // 12 ────────────────────────────────────────────────────────────────────
    public function test_a_deleted_message_stops_asking_for_attention(): void
    {
        [$priscilla, $moses] = [$this->staff('Priscilla'), $this->staff('Moses')];
        $dm = $this->conversation('dm', $priscilla, $moses);
        $id = $this->say($priscilla, $dm, 'Sent to the wrong person');

        Sanctum::actingAs($priscilla);
        $this->deleteJson("/api/v1/admin/channels/{$dm->id}/messages/{$id}")->assertOk();

        $this->assertNull($this->row($moses, $dm));
    }

    // 13 ────────────────────────────────────────────────────────────────────
    public function test_after_being_offline_the_same_notices_are_still_there(): void
    {
        [$priscilla, $moses] = [$this->staff('Priscilla'), $this->staff('Moses')];
        $dm = $this->conversation('dm', $priscilla, $moses);

        // Messages land while Moses' laptop is asleep — no client is listening.
        $this->say($priscilla, $dm, 'Out of chains');
        $this->say($priscilla, $dm, 'Out of containers');

        // On wake the console asks once and gets everything it missed.
        $first  = $this->attention($moses);
        $second = $this->attention($moses);
        $this->assertSame(2, $first['channels'][0]['notice']['count']);
        $this->assertSame($first['channels'], $second['channels'], 'asking again changes nothing');
    }

    // 14 ────────────────────────────────────────────────────────────────────
    public function test_reading_announces_to_the_readers_other_tabs_only_when_something_changed(): void
    {
        [$priscilla, $moses] = [$this->staff('Priscilla'), $this->staff('Moses')];
        $dm = $this->conversation('dm', $priscilla, $moses);
        $this->say($priscilla, $dm, 'Hello');

        Event::fake([MessageAttentionChanged::class]);
        Sanctum::actingAs($moses);
        $this->postJson("/api/v1/admin/channels/{$dm->id}/read")->assertOk();
        $this->postJson("/api/v1/admin/channels/{$dm->id}/read")->assertOk();

        Event::assertDispatchedTimes(MessageAttentionChanged::class, 1);
        Event::assertDispatched(MessageAttentionChanged::class, fn ($e) => $e->userId === $moses->id && $e->channelId === $dm->id && $e->reason === 'read');
    }
}

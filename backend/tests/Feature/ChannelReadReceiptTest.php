<?php

namespace Tests\Feature;

use App\Events\ChannelReadUpdated;
use App\Models\Channel;
use App\Models\ChannelMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Read receipts. The state is each member's read pointer
 * (channel_members.last_read_message_id) — a message is read by a member
 * exactly when their pointer ≥ its id. The messages payload exposes every
 * member's pointer; marking read broadcasts read.updated only when the
 * pointer actually advances, and never moves it backwards.
 */
class ChannelReadReceiptTest extends TestCase
{
    use RefreshDatabase;

    private function member(Channel $channel): User
    {
        $user = User::factory()->create();
        DB::table('channel_members')->insert([
            'channel_id' => $channel->id, 'user_id' => $user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    private function channel(): Channel
    {
        return Channel::create([
            'type' => 'space', 'name' => 'Tailors', 'is_private' => true,
        ]);
    }

    private function message(Channel $channel, User $author): ChannelMessage
    {
        return ChannelMessage::create([
            'channel_id' => $channel->id,
            'user_id'    => $author->id,
            'type'       => 'text',
            'body'       => 'Cassock batch is through QC',
        ]);
    }

    public function test_messages_payload_carries_every_members_read_pointer(): void
    {
        $channel = $this->channel();
        $author  = $this->member($channel);
        $reader  = $this->member($channel);
        $message = $this->message($channel, $author);

        Sanctum::actingAs($reader);
        $this->postJson("/api/v1/admin/channels/{$channel->id}/read")->assertOk();

        Sanctum::actingAs($author);
        $reads = collect(
            $this->getJson("/api/v1/admin/channels/{$channel->id}/messages")
                ->assertOk()
                ->json('reads')
        );

        $this->assertCount(2, $reads);
        $row = $reads->firstWhere('user_id', $reader->id);
        $this->assertSame($message->id, $row['last_read_message_id']);
        $this->assertNotSame('', $row['name']);

        // The author has not marked read; their pointer is still unset.
        $this->assertNull($reads->firstWhere('user_id', $author->id)['last_read_message_id']);
    }

    public function test_mark_read_broadcasts_only_when_the_pointer_advances(): void
    {
        $channel = $this->channel();
        $author  = $this->member($channel);
        $reader  = $this->member($channel);
        $message = $this->message($channel, $author);

        Event::fake([ChannelReadUpdated::class]);
        Sanctum::actingAs($reader);

        $this->postJson("/api/v1/admin/channels/{$channel->id}/read")->assertOk();
        Event::assertDispatched(ChannelReadUpdated::class, fn ($e) =>
            $e->channelId === $channel->id
            && $e->userId === $reader->id
            && $e->lastReadMessageId === $message->id
        );

        // Reopening an already-read thread is silent — no second broadcast.
        $this->postJson("/api/v1/admin/channels/{$channel->id}/read")->assertOk();
        Event::assertDispatchedTimes(ChannelReadUpdated::class, 1);
    }

    public function test_an_empty_channel_marks_read_without_broadcasting(): void
    {
        $channel = $this->channel();
        $reader  = $this->member($channel);

        Event::fake([ChannelReadUpdated::class]);
        Sanctum::actingAs($reader);

        $this->postJson("/api/v1/admin/channels/{$channel->id}/read")->assertOk();
        Event::assertNotDispatched(ChannelReadUpdated::class);
    }
}

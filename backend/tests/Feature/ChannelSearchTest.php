<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /channels/search — the chat list's search box reaches into message text.
 * Only your own conversations answer; one row per channel; the match is the
 * snippet; case does not matter.
 */
class ChannelSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
    }

    private function staff(string $first): User
    {
        return User::factory()->create(['status' => 'active', 'user_type' => 'staff', 'first_name' => $first, 'last_name' => 'Staff']);
    }

    private function channelWith(string $name, User ...$members): Channel
    {
        $c = Channel::create(['name' => $name, 'type' => 'space', 'created_by' => $members[0]->id]);
        foreach ($members as $m) {
            DB::table('channel_members')->insert(['channel_id' => $c->id, 'user_id' => $m->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $c;
    }

    private function say(Channel $c, User $u, string $body): ChannelMessage
    {
        return ChannelMessage::create(['channel_id' => $c->id, 'user_id' => $u->id, 'type' => 'text', 'body' => $body]);
    }

    public function test_it_finds_only_my_channels_one_row_each_with_the_newest_match_as_snippet(): void
    {
        $me    = $this->staff('Me');
        $other = $this->staff('Other');
        $mine  = $this->channelWith('Workshop', $me, $other);
        $theirs = $this->channelWith('Private', $other);

        $this->say($mine, $other, 'The lining FABRIC arrived this morning');
        $this->say($mine, $me, 'Which fabric? The navy one?');
        $this->say($theirs, $other, 'fabric fabric fabric');           // not my channel
        $this->say($mine, $other, 'Nothing to do with cloth');          // no match

        Sanctum::actingAs($me);
        $res = $this->getJson('/api/v1/admin/channels/search?q=fabric')->assertOk();
        $hits = $res->json('hits');

        $this->assertCount(1, $hits);
        $this->assertSame($mine->id, $hits[0]['channel_id']);
        $this->assertSame(2, $hits[0]['count']);
        $this->assertSame('Which fabric? The navy one?', $hits[0]['snippet']);   // newest match first
    }

    public function test_tokens_are_flattened_in_the_snippet_and_short_queries_are_refused(): void
    {
        $me = $this->staff('Me');
        $c  = $this->channelWith('Workshop', $me);
        $this->say($c, $me, 'Buttons for #[PO-261018](entity:production_order:9) are with @[Beyonce Tamarah](user:4)');

        Sanctum::actingAs($me);
        $this->getJson('/api/v1/admin/channels/search?q=buttons')
            ->assertOk()->assertJsonPath('hits.0.snippet', 'Buttons for #PO-261018 are with @Beyonce Tamarah');
        $this->getJson('/api/v1/admin/channels/search?q=b')->assertStatus(422);
        $this->getJson('/api/v1/admin/channels/search')->assertStatus(422);
    }

    public function test_a_stranger_gets_no_hits_and_a_guest_is_refused(): void
    {
        $me = $this->staff('Me');
        $c  = $this->channelWith('Workshop', $me);
        $this->say($c, $me, 'fabric here');

        Sanctum::actingAs($this->staff('Stranger'));
        $this->getJson('/api/v1/admin/channels/search?q=fabric')->assertOk()->assertJsonCount(0, 'hits');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/channels/search?q=fabric')->assertStatus(401);
    }
}

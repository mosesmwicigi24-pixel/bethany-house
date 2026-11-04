<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\User;
use App\Services\Comms\PushActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Message banners above other programs (owner, 2026-11-04): Acknowledge and
 * ✕ on the system banner act without opening the hub. The service worker has
 * no login, so each banner carries links signed for one person, one
 * conversation and one message.
 */
class MessageBannerActionsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $first): User
    {
        return User::factory()->create(['first_name' => $first, 'last_name' => 'Test', 'status' => 'active']);
    }

    private function dm(User ...$members): Channel
    {
        $channel = Channel::create(['type' => 'dm', 'name' => null, 'is_private' => true]);
        foreach ($members as $m) {
            DB::table('channel_members')->insert(['channel_id' => $channel->id, 'user_id' => $m->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
        }
        return $channel;
    }

    private function say(User $who, Channel $channel, string $body): int
    {
        Sanctum::actingAs($who);
        return (int) $this->postJson("/api/v1/admin/channels/{$channel->id}/messages", ['body' => $body])->assertCreated()->json('message.id');
    }

    private function row(User $who, Channel $channel): ?array
    {
        Sanctum::actingAs($who);
        return collect($this->getJson('/api/v1/admin/channels/attention')->assertOk()->json('channels'))->firstWhere('channel_id', $channel->id);
    }

    /** The banner's button, pressed with no login at all. */
    private function press(string $path)
    {
        $this->app['auth']->forgetGuards();
        return $this->postJson($path);
    }

    public function test_acknowledge_on_the_banner_handles_and_reads_the_message_without_a_login(): void
    {
        [$priscilla, $moses] = [$this->staff('Priscilla'), $this->staff('Moses')];
        $dm = $this->dm($priscilla, $moses);
        $id = $this->say($priscilla, $dm, 'We are out of chains');
        $this->assertSame(1, $this->row($moses, $dm)['pending_count']);

        $links = PushActions::links($moses->id, $dm->id, $id);
        $this->assertStringStartsWith('/api/v1/push-actions/channels/', $links['acknowledge'], 'a path, so it works behind any host or proxy');

        $this->press($links['acknowledge'])->assertOk()->assertJson(['ok' => true, 'up_to' => $id]);

        $row = $this->row($moses, $dm);
        $this->assertTrue($row === null || ($row['pending_count'] === 0 && $row['unread_count'] === 0 && $row['notice'] === null));
        $this->press($links['acknowledge'])->assertOk();             // pressing twice is harmless
    }

    public function test_the_banner_close_dismisses_the_notice_but_the_message_stays_unread_and_waiting(): void
    {
        [$priscilla, $moses] = [$this->staff('Priscilla'), $this->staff('Moses')];
        $dm = $this->dm($priscilla, $moses);
        $id = $this->say($priscilla, $dm, 'Chalices need 10 days');

        $this->press(PushActions::links($moses->id, $dm->id, $id)['dismiss'])->assertOk();

        $row = $this->row($moses, $dm);
        $this->assertNull($row['notice']);
        $this->assertSame(1, $row['unread_count']);
        $this->assertSame(1, $row['pending_count']);
    }

    public function test_a_banner_acts_only_up_to_its_own_message_so_a_newer_one_is_not_handled_unseen(): void
    {
        [$priscilla, $moses] = [$this->staff('Priscilla'), $this->staff('Moses')];
        $dm = $this->dm($priscilla, $moses);
        $first = $this->say($priscilla, $dm, 'Containers for 200 pieces of bread');
        $this->say($priscilla, $dm, 'And the 365-day children\'s Bible');

        $this->press(PushActions::links($moses->id, $dm->id, $first)['acknowledge'])->assertOk()->assertJson(['up_to' => $first]);

        $row = $this->row($moses, $dm);
        $this->assertSame(1, $row['unread_count'], 'the newer message is still unread');
        $this->assertGreaterThan(0, $row['pending_count'], 'and still waiting on Moses');
    }

    public function test_an_altered_expired_or_borrowed_link_does_nothing(): void
    {
        [$priscilla, $moses, $outsider] = [$this->staff('Priscilla'), $this->staff('Moses'), $this->staff('Outsider')];
        $dm = $this->dm($priscilla, $moses);
        $id = $this->say($priscilla, $dm, 'Private note');
        $links = PushActions::links($moses->id, $dm->id, $id);

        // Someone else's id swapped in: the signature no longer matches.
        $this->press(str_replace("user={$moses->id}", "user={$outsider->id}", $links['acknowledge']))->assertStatus(403);
        // Unsigned.
        $this->press("/api/v1/push-actions/channels/{$dm->id}/acknowledge?user={$moses->id}&message={$id}")->assertStatus(403);
        // A link signed for someone who is not in the conversation.
        $this->press(PushActions::links($outsider->id, $dm->id, $id)['acknowledge'])->assertStatus(403);
        // A deactivated account.
        $moses->update(['status' => 'inactive']);
        $this->press($links['acknowledge'])->assertStatus(403);
        $moses->update(['status' => 'active']);
        // After 24 hours.
        $this->travel(PushActions::TTL_HOURS * 60 + 1)->minutes();
        $this->press($links['acknowledge'])->assertStatus(403);

        $this->travelBack();
        $this->assertSame(1, $this->row($moses, $dm)['pending_count'], 'nothing was handled');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Editing and deleting your own messages in the comms hub. The endpoints
 * existed but the admin UI never exposed an edit affordance; these lock the
 * rules the UI now relies on — author-only edits, a 10-minute window, an
 * edited_at marker, and admin moderation of anyone's message.
 */
class ChannelMessageEditDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function member(Channel $channel, array $roles = []): User
    {
        $user = User::factory()->create();
        foreach ($roles as $r) {
            $user->assignRole(Role::findOrCreate($r, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::table('channel_members')->insert([
            'channel_id' => $channel->id, 'user_id' => $user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    private function channel(): Channel
    {
        return Channel::create([
            'type' => 'space', 'name' => 'Quality Control', 'is_private' => true,
        ]);
    }

    private function message(Channel $channel, User $author, array $attrs = []): ChannelMessage
    {
        return ChannelMessage::create(array_merge([
            'channel_id' => $channel->id,
            'user_id'    => $author->id,
            'type'       => 'space',
            'body'       => 'Good morning all',
        ], $attrs));
    }

    public function test_author_can_edit_own_message_and_it_is_marked_edited(): void
    {
        $channel = $this->channel();
        $author  = $this->member($channel);
        $msg     = $this->message($channel, $author);

        Sanctum::actingAs($author);
        $this->patchJson("/api/v1/admin/channels/{$channel->id}/messages/{$msg->id}", [
            'body' => 'Good morning team',
        ])->assertOk();

        $fresh = $msg->fresh();
        $this->assertSame('Good morning team', $fresh->body);
        $this->assertNotNull($fresh->edited_at);
    }

    public function test_cannot_edit_someone_elses_message(): void
    {
        $channel = $this->channel();
        $author  = $this->member($channel);
        $other   = $this->member($channel);
        $msg     = $this->message($channel, $author);

        Sanctum::actingAs($other);
        $this->patchJson("/api/v1/admin/channels/{$channel->id}/messages/{$msg->id}", [
            'body' => 'hijacked',
        ])->assertStatus(403);

        $this->assertSame('Good morning all', $msg->fresh()->body);
    }

    public function test_edit_window_closes_after_ten_minutes(): void
    {
        $channel = $this->channel();
        $author  = $this->member($channel);
        $msg     = $this->message($channel, $author);
        // Older than the window the UI hides the action after.
        $msg->forceFill(['created_at' => now()->subMinutes(11)])->save();

        Sanctum::actingAs($author);
        $this->patchJson("/api/v1/admin/channels/{$channel->id}/messages/{$msg->id}", [
            'body' => 'too late',
        ])->assertStatus(422);

        $this->assertSame('Good morning all', $msg->fresh()->body);
    }

    public function test_author_can_delete_own_message_at_any_age(): void
    {
        $channel = $this->channel();
        $author  = $this->member($channel);
        $msg     = $this->message($channel, $author);
        $msg->forceFill(['created_at' => now()->subDays(3)])->save();

        Sanctum::actingAs($author);
        $this->deleteJson("/api/v1/admin/channels/{$channel->id}/messages/{$msg->id}")->assertOk();

        $this->assertNull(ChannelMessage::find($msg->id));
    }

    public function test_non_author_non_admin_cannot_delete(): void
    {
        $channel = $this->channel();
        $author  = $this->member($channel);
        $other   = $this->member($channel);
        $msg     = $this->message($channel, $author);

        Sanctum::actingAs($other);
        $this->deleteJson("/api/v1/admin/channels/{$channel->id}/messages/{$msg->id}")->assertStatus(403);

        $this->assertNotNull(ChannelMessage::find($msg->id));
    }

    public function test_admin_can_moderate_anyone_elses_message(): void
    {
        $channel = $this->channel();
        $author  = $this->member($channel);
        $admin   = $this->member($channel, ['admin']);
        $msg     = $this->message($channel, $author);

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/v1/admin/channels/{$channel->id}/messages/{$msg->id}")->assertOk();

        $this->assertNull(ChannelMessage::find($msg->id));
    }

    public function test_non_member_cannot_touch_messages(): void
    {
        $channel = $this->channel();
        $author  = $this->member($channel);
        $msg     = $this->message($channel, $author);

        $outsider = User::factory()->create(); // deliberately not a member
        Sanctum::actingAs($outsider);

        $this->patchJson("/api/v1/admin/channels/{$channel->id}/messages/{$msg->id}", ['body' => 'x'])
            ->assertStatus(403);
        $this->deleteJson("/api/v1/admin/channels/{$channel->id}/messages/{$msg->id}")
            ->assertStatus(403);
    }
}

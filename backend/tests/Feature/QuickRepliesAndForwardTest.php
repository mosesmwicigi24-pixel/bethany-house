<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelMessage;
use App\Models\QuickReply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Messages, Business extras (PR 4): quick replies and forwarding.
 */
class QuickRepliesAndForwardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
    }

    private function staff(string $first, ?string $role = null): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff', 'first_name' => $first, 'last_name' => 'Staff']);
        if ($role) {
            $u->assignRole(Role::findOrCreate($role, 'sanctum'));
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return $u;
    }

    private function channelWith(string $name, User ...$members): Channel
    {
        $c = Channel::create(['name' => $name, 'type' => 'space', 'created_by' => $members[0]->id]);
        foreach ($members as $m) {
            DB::table('channel_members')->insert(['channel_id' => $c->id, 'user_id' => $m->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $c;
    }

    public function test_every_staff_member_reads_quick_replies_but_only_admins_write_them(): void
    {
        $admin = $this->staff('Ada', 'admin');
        $staff = $this->staff('Sam');

        Sanctum::actingAs($staff);
        $this->postJson('/api/v1/admin/channels/quick-replies', ['title' => 'Ready', 'body' => 'Your order {order} is ready for collection.'])->assertStatus(403);

        Sanctum::actingAs($admin);
        $id = $this->postJson('/api/v1/admin/channels/quick-replies', ['title' => 'Ready', 'body' => 'Your order {order} is ready for collection.'])
            ->assertCreated()->json('quick_reply.id');
        $this->postJson('/api/v1/admin/channels/quick-replies', ['title' => 'R', 'body' => ''])->assertStatus(422);
        $this->patchJson("/api/v1/admin/channels/quick-replies/{$id}", ['title' => 'Ready for collection', 'body' => 'Hello {customer}, {order} is ready.'])
            ->assertOk()->assertJsonPath('quick_reply.title', 'Ready for collection');

        Sanctum::actingAs($staff);
        $this->getJson('/api/v1/admin/channels/quick-replies')->assertOk()
            ->assertJsonCount(1, 'quick_replies')->assertJsonPath('quick_replies.0.body', 'Hello {customer}, {order} is ready.');
        $this->deleteJson("/api/v1/admin/channels/quick-replies/{$id}")->assertStatus(403);

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/v1/admin/channels/quick-replies/{$id}")->assertOk();
        $this->assertSame(0, QuickReply::count());
    }

    public function test_forwarding_posts_the_message_again_in_a_conversation_you_both_belong_to(): void
    {
        $me    = $this->staff('Me');
        $other = $this->staff('Other');
        $src   = $this->channelWith('Production floor', $me, $other);
        $dst   = $this->channelWith('Front desk', $me);
        $notMine = $this->channelWith('Private', $other);
        $m = ChannelMessage::create(['channel_id' => $src->id, 'user_id' => $other->id, 'type' => 'text', 'body' => 'Buttons are in the drawer by the press']);

        Sanctum::actingAs($me);
        $res = $this->postJson("/api/v1/admin/channels/{$src->id}/messages/{$m->id}/forward", ['to_channel_id' => $dst->id])->assertCreated();
        $this->assertSame($dst->id, $res->json('channel_id'));
        $this->assertSame("↪ Forwarded from Production floor\nButtons are in the drawer by the press", $res->json('message.body'));
        $this->assertSame($me->id, $res->json('message.user.id'));
        $this->assertSame(1, ChannelMessage::where('channel_id', $dst->id)->count());

        // Not a member of the target → refused; same conversation → refused.
        $this->postJson("/api/v1/admin/channels/{$src->id}/messages/{$m->id}/forward", ['to_channel_id' => $notMine->id])->assertStatus(403);
        $this->postJson("/api/v1/admin/channels/{$src->id}/messages/{$m->id}/forward", ['to_channel_id' => $src->id])->assertStatus(422);

        // Not a member of the source → nothing to forward.
        Sanctum::actingAs($this->staff('Stranger'));
        $this->postJson("/api/v1/admin/channels/{$src->id}/messages/{$m->id}/forward", ['to_channel_id' => $dst->id])->assertStatus(403);
    }
}

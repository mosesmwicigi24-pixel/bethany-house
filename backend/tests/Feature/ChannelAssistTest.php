<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelMessage;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use App\Services\Comms\AssistModel;
use App\Services\Comms\FakeAssistModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Messages Assist rail (owner's redesign, 22 Oct 2026, PR 3): members
 * only; the model sees the thread with entity tokens flattened and, on an
 * order thread, the live order; every tap is audited without its text; and
 * a server with no key says so instead of failing.
 */
class ChannelAssistTest extends TestCase
{
    use RefreshDatabase;

    private FakeAssistModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        $this->model = new FakeAssistModel();
        $this->app->instance(AssistModel::class, $this->model);
    }

    private function staff(): User
    {
        return User::factory()->create(['status' => 'active', 'user_type' => 'staff', 'first_name' => 'Priscilla', 'last_name' => 'Ngari']);
    }

    private function channelWith(User ...$members): Channel
    {
        $c = Channel::create(['name' => 'Workshop', 'type' => 'space', 'created_by' => $members[0]->id]);
        foreach ($members as $m) {
            DB::table('channel_members')->insert(['channel_id' => $c->id, 'user_id' => $m->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $c;
    }

    private function say(Channel $c, User $u, string $body): void
    {
        ChannelMessage::create(['channel_id' => $c->id, 'user_id' => $u->id, 'type' => 'text', 'body' => $body]);
    }

    public function test_a_member_gets_a_summary_and_the_model_sees_the_thread_with_tokens_flattened(): void
    {
        $me = $this->staff();
        $c  = $this->channelWith($me);
        $this->say($c, $me, 'Buttons for #[PO-261018](entity:production_order:9) arrived, @[Beyonce Tamarah](user:4) please confirm fitting Friday 2pm.');

        Sanctum::actingAs($me);
        $this->postJson("/api/v1/admin/channels/{$c->id}/assist", ['action' => 'summarise'])
            ->assertOk()->assertJsonPath('action', 'summarise')->assertJsonStructure(['text']);

        $this->assertCount(1, $this->model->calls);
        $sent = $this->model->calls[0]['user'];
        $this->assertStringContainsString('Priscilla Ngari (', $sent);
        $this->assertStringContainsString('Buttons for PO-261018 arrived, @Beyonce Tamarah please confirm fitting Friday 2pm.', $sent);
        $this->assertStringNotContainsString('entity:production_order', $sent);
        $this->assertStringNotContainsString('(user:4)', $sent);

        $this->assertDatabaseHas('activity_log', ['event' => 'assist_used', 'causer_id' => $me->id]);
        $row = DB::table('activity_log')->where('event', 'assist_used')->latest('id')->first();
        $this->assertStringNotContainsString('Buttons', (string) $row->properties, 'the audit row carries sizes, never the text');
        $this->assertSame('summarise', json_decode((string) $row->properties, true)['action'] ?? null);
    }

    public function test_an_order_thread_puts_the_live_order_in_front_of_the_model(): void
    {
        $me     = $this->staff();
        $outlet = Outlet::factory()->create(['country_code' => 'KE']);
        $order  = Order::factory()->create(['outlet_id' => $outlet->id, 'customer_first_name' => 'Wincate', 'customer_last_name' => 'Njoroge',
            'customer_phone' => '0722000111', 'status' => 'confirmed', 'payment_status' => 'paid', 'total_amount' => 13500, 'currency_code' => 'KES']);
        $c = Channel::create(['name' => "Order {$order->order_number}", 'type' => 'space', 'created_by' => $me->id, 'context_type' => 'order', 'context_id' => $order->id]);
        DB::table('channel_members')->insert(['channel_id' => $c->id, 'user_id' => $me->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
        $this->say($c, $me, 'Ready for fitting Friday.');

        Sanctum::actingAs($me);
        $this->postJson("/api/v1/admin/channels/{$c->id}/assist", ['action' => 'draft', 'audience' => 'customer', 'language' => 'sw'])->assertOk();

        $sent = $this->model->calls[0]['user'];
        $this->assertStringContainsString("ORDER {$order->order_number} (sales order)", $sent);
        $this->assertStringContainsString('Customer: Wincate Njoroge', $sent);
        $this->assertStringContainsString('Total: KES 13,500.00', $sent);
        $this->assertStringContainsString('Paid: paid', $sent);
        $this->assertStringContainsString('Kiswahili', $this->model->calls[0]['system']);
    }

    public function test_a_customer_draft_needs_an_order_thread(): void
    {
        $me = $this->staff();
        $c  = $this->channelWith($me);
        Sanctum::actingAs($me);

        $this->postJson("/api/v1/admin/channels/{$c->id}/assist", ['action' => 'draft', 'audience' => 'customer'])
            ->assertStatus(422)->assertJsonPath('code', 'assist_unavailable');
        $this->assertCount(0, $this->model->calls);
    }

    public function test_translate_sends_the_draft_and_replies_come_back_as_three_options(): void
    {
        $me = $this->staff();
        $c  = $this->channelWith($me);
        $this->say($c, $me, 'Can we collect tomorrow?');
        Sanctum::actingAs($me);

        $this->postJson("/api/v1/admin/channels/{$c->id}/assist", ['action' => 'translate', 'language' => 'sw', 'text' => 'The shirts are ready for fitting on Friday at 2 pm.'])
            ->assertOk()->assertJsonStructure(['text']);
        $this->assertSame('The shirts are ready for fitting on Friday at 2 pm.', $this->model->calls[0]['user']);

        // The route keeps its controller (and so the model) for the test's lifetime: script the answer in place.
        $this->model->answer = "Yes, tomorrow works.\n\"What time suits you?\"\n- I will confirm with the workshop.";
        $res = $this->postJson("/api/v1/admin/channels/{$c->id}/assist", ['action' => 'replies'])->assertOk();
        $this->assertSame(['Yes, tomorrow works.', 'What time suits you?', 'I will confirm with the workshop.'], $res->json('options'));

        $this->postJson("/api/v1/admin/channels/{$c->id}/assist", ['action' => 'translate', 'language' => 'sw', 'text' => '   '])->assertStatus(422);
    }

    public function test_non_members_and_bad_actions_are_refused(): void
    {
        $owner = $this->staff();
        $c     = $this->channelWith($owner);
        $other = $this->staff();

        Sanctum::actingAs($other);
        $this->postJson("/api/v1/admin/channels/{$c->id}/assist", ['action' => 'summarise'])->assertForbidden();

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/admin/channels/{$c->id}/assist", ['action' => 'rewrite'])->assertStatus(422);
        $this->assertCount(0, $this->model->calls);
    }

    public function test_a_server_without_a_key_says_so(): void
    {
        $this->app->instance(AssistModel::class, new FakeAssistModel(isConfigured: false));
        $me = $this->staff();
        $c  = $this->channelWith($me);
        Sanctum::actingAs($me);

        $this->postJson("/api/v1/admin/channels/{$c->id}/assist", ['action' => 'summarise'])
            ->assertStatus(503)->assertJsonPath('code', 'assist_not_configured');
    }
}

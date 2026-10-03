<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelMessage;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Role hardening 4D: a notification carries ids and a short summary — never a
 * customer contact, and never an amount the recipient's own rights would not
 * show them. Recipients chosen by role name are re-checked against the
 * permission the payload needs.
 */
class NotificationPayloadScopeTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(string $role, array $permissions = []): User
    {
        $r = Role::findOrCreate($role, 'sanctum');
        foreach ($permissions as $p) {
            $r->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $u->assignRole($r);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return $u;
    }

    private function notificationsFor(User $u): array
    {
        return DB::table('notifications')->where('notifiable_id', $u->id)->pluck('data')->all();
    }

    public function test_a_lead_notification_never_carries_the_customers_phone(): void
    {
        $owner = $this->userWith('super_admin');

        NotificationService::leadCaptured(77, '+254 722 123 456', 'quote');

        $rows = $this->notificationsFor($owner);
        $this->assertCount(1, $rows);
        $this->assertStringNotContainsString('722', $rows[0]);
        $this->assertStringContainsString('"lead_id":77', str_replace(' ', '', $rows[0]));
    }

    public function test_a_payment_amount_reaches_only_holders_of_payments_view(): void
    {
        $finance  = $this->userWith('finance_manager', ['payments.view']);
        $narrowed = $this->userWith('admin');   // admin role edited down: no payments.view

        NotificationService::paymentReceived(1, 'PMT-1', 1, 'ORD-1', 125000, 'KES', 'cash');

        $this->assertCount(1, $this->notificationsFor($finance));
        $this->assertSame([], $this->notificationsFor($narrowed), 'an amount went to someone without payments.view');
    }

    public function test_a_purchase_order_total_is_left_out_for_recipients_without_cost_rights(): void
    {
        $withCost    = $this->userWith('procurement_manager', ['procurement.view', 'products.view_cost']);
        $withoutCost = $this->userWith('procurement_officer', ['procurement.view']);

        NotificationService::purchaseOrderCreated(9, 'PO-9', 'Kitenge Mills', 48250.50);

        $this->assertStringContainsString('48,250.50', $this->notificationsFor($withCost)[0]);
        $other = $this->notificationsFor($withoutCost);
        $this->assertCount(1, $other, 'the officer should still hear about the PO');
        $this->assertStringNotContainsString('48,250', $other[0]);
    }

    public function test_a_mention_reaches_only_members_of_the_conversation(): void
    {
        $channel = Channel::create(['type' => 'space', 'name' => 'Finance', 'is_private' => true]);
        $member  = $this->userWith('finance_manager');
        $outsider = $this->userWith('pos_clerk');
        DB::table('channel_members')->insert(['channel_id' => $channel->id, 'user_id' => $member->id, 'created_at' => now(), 'updated_at' => now()]);
        $msg = ChannelMessage::create(['channel_id' => $channel->id, 'user_id' => $member->id, 'type' => 'text', 'body' => 'Client 0722000111 owes 90,000']);

        NotificationService::channelMention($outsider->id, 'Ann', 'Finance', 'Client 0722000111 owes 90,000', '/comms', $msg->id);
        NotificationService::channelMention($member->id, 'Ann', 'Finance', 'Client 0722000111 owes 90,000', '/comms', $msg->id);

        $this->assertSame([], $this->notificationsFor($outsider), 'a mention leaked a private conversation to a non-member');
        $this->assertCount(1, $this->notificationsFor($member));
    }
}

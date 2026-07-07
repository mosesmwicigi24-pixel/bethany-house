<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use App\Notifications\OrderPlacedNotification;
use App\Notifications\PaymentReceivedNotification;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Notifications must reach the people responsible for each area, scoped to the
 * right outlet, and must never ping the person who performed the action.
 */
class NotificationRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole(Role::findOrCreate($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return $user;
    }

    public function test_payment_notifications_go_to_finance_and_owners_but_not_the_actor(): void
    {
        Notification::fake();

        $owner    = $this->userWithRole('super_admin');
        $finance  = $this->userWithRole('finance_manager');
        $actor    = $this->userWithRole('admin');   // the admin who records the payment
        $otherAdm = $this->userWithRole('admin');
        $clerk    = $this->userWithRole('pos_clerk');   // not a money role

        $this->actingAs($actor);   // performing the action

        NotificationService::paymentReceived(1, 'PMT-1', 1, 'ORD-1', 500, 'KES', 'cash');

        // Finance + the other owners are told …
        Notification::assertSentTo([$owner, $finance, $otherAdm], PaymentReceivedNotification::class);
        // … the person who recorded it is NOT pinged about their own action …
        Notification::assertNotSentTo($actor, PaymentReceivedNotification::class);
        // … and a POS clerk (no money responsibility) is never in the loop.
        Notification::assertNotSentTo($clerk, PaymentReceivedNotification::class);
    }

    public function test_order_placed_is_scoped_to_the_managers_of_that_outlet(): void
    {
        Notification::fake();

        $owner    = $this->userWithRole('super_admin');
        $managerA = $this->userWithRole('outlet_manager');
        $managerB = $this->userWithRole('outlet_manager');

        $outletA = Outlet::factory()->create();
        $outletB = Outlet::factory()->create();
        $managerA->outlets()->attach($outletA->id, ['is_primary' => true]);
        $managerB->outlets()->attach($outletB->id, ['is_primary' => true]);

        // No authenticated actor here → nobody is excluded.
        NotificationService::orderPlaced(1, 'ORD-1', $outletA->id);

        Notification::assertSentTo($owner, OrderPlacedNotification::class);      // owners see all
        Notification::assertSentTo($managerA, OrderPlacedNotification::class);   // their outlet
        Notification::assertNotSentTo($managerB, OrderPlacedNotification::class); // other outlet
    }

    public function test_finance_manager_actually_receives_payment_notifications(): void
    {
        // Regression guard for the old bug where the code queried a non-existent
        // 'finance' role, so finance_manager received nothing.
        Notification::fake();

        $finance = $this->userWithRole('finance_manager');

        NotificationService::paymentReceived(1, 'PMT-1', 1, 'ORD-1', 500, 'KES', 'cash');

        Notification::assertSentTo($finance, PaymentReceivedNotification::class);
    }
}

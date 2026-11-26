<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionStage;
use App\Models\ProductionTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Who does a production stage by default (owner, 2026-11-26).
 *
 * Information Desk and Payment go to whoever is serving the customer;
 * Material Sourcing, Quality Check and Laundry each to one named person.
 * Set once on the stage, it stays until changed, and a new production order
 * picks it up. Stitching has no default and is handed out as before.
 */
class ProductionStageDefaultAssigneeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $server;     // serves the customer
    private User $sourcing;   // the stores person
    private User $quality;
    /** @var array<string, ProductionStage> */
    private array $stage = [];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');

        $this->owner    = $this->person('super_admin');
        $this->server   = $this->person('pos_clerk');
        $this->sourcing = $this->person('tailor');
        $this->quality  = $this->person('tailor');

        foreach (['Information Desk', 'Payment', 'Material Sourcing', 'Stitching', 'Quality Check', 'Laundry'] as $i => $name) {
            $this->stage[$name] = ProductionStage::create([
                'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'sort_order' => $i + 1, 'is_active' => true,
            ]);
        }

        Sanctum::actingAs($this->owner);
    }

    private function person(string $role): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $u->assignRole(Role::findByName($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function set(string $stage, string $mode, ?User $who = null)
    {
        return $this->putJson("/api/v1/admin/product-stages/{$this->stage[$stage]->id}", [
            'default_assignee_mode' => $mode, 'default_assignee_id' => $who?->id,
        ]);
    }

    private function job(?User $servedBy = null, ?User $raisedBy = null): ProductionOrder
    {
        $customerOrder = $servedBy ? Order::withoutViewerScope()->create([
            'order_number' => 'T-STAGE-' . uniqid(), 'status' => 'confirmed', 'payment_status' => 'paid',
            'currency_code' => 'KES', 'subtotal' => 1000, 'total_amount' => 1000, 'created_by' => $servedBy->id,
        ]) : null;

        return ProductionOrder::create([
            'order_number' => 'PRD-STAGE-' . uniqid(), 'product_id' => Product::factory()->create()->id,
            'quantity' => 1, 'status' => 'pending',
            'customer_order_id' => $customerOrder?->id, 'created_by' => ($raisedBy ?? $this->owner)->id,
        ]);
    }

    /** @return array<string, ?int> stage name => assigned_to */
    private function whoHas(ProductionOrder $job): array
    {
        return $job->tasks()->with('stage')->orderBy('sequence')->get()
            ->mapWithKeys(fn (ProductionTask $t) => [$t->stage->name => $t->assigned_to])->all();
    }

    private function theOwnersSetup(): void
    {
        $this->set('Information Desk', 'order_creator')->assertOk();
        $this->set('Payment', 'order_creator')->assertOk();
        $this->set('Material Sourcing', 'person', $this->sourcing)->assertOk();
        $this->set('Quality Check', 'person', $this->quality)->assertOk();
        $this->set('Laundry', 'person', $this->sourcing)->assertOk();
    }

    public function test_a_new_job_hands_each_stage_to_its_default_and_leaves_the_rest_open(): void
    {
        $this->theOwnersSetup();

        $job = $this->job(servedBy: $this->server);
        $job->seedTasks();

        $this->assertSame([
            'Information Desk'  => $this->server->id,
            'Payment'           => $this->server->id,
            'Material Sourcing' => $this->sourcing->id,
            'Stitching'         => null,
            'Quality Check'     => $this->quality->id,
            'Laundry'           => $this->sourcing->id,
        ], $this->whoHas($job));
    }

    public function test_it_stays_set_for_the_next_job_until_it_is_reset(): void
    {
        $this->theOwnersSetup();

        $first = $this->job(servedBy: $this->server);
        $first->seedTasks();
        $second = $this->job(servedBy: $this->quality);   // somebody else serving
        $second->seedTasks();

        $this->assertSame($this->sourcing->id, $this->whoHas($second)['Material Sourcing'], 'still the same person');
        $this->assertSame($this->quality->id, $this->whoHas($second)['Information Desk'], 'whoever served THIS customer');

        // Reset: back to being handed out by hand.
        $this->set('Material Sourcing', 'none')->assertOk();
        $this->assertNull($this->stage['Material Sourcing']->fresh()->default_assignee_id);

        $third = $this->job(servedBy: $this->server);
        $third->seedTasks();
        $this->assertNull($this->whoHas($third)['Material Sourcing']);
        $this->assertSame($this->quality->id, $this->whoHas($third)['Quality Check'], 'the others are untouched');
    }

    public function test_changing_a_default_never_moves_work_already_on_the_floor(): void
    {
        $this->theOwnersSetup();
        $job = $this->job(servedBy: $this->server);
        $job->seedTasks();

        // Somebody takes Stitching by hand; then the stores person changes.
        $job->tasks()->where('production_stage_id', $this->stage['Stitching']->id)->update(['assigned_to' => $this->quality->id]);
        $this->set('Material Sourcing', 'person', $this->quality)->assertOk();
        $this->set('Stitching', 'person', $this->sourcing)->assertOk();

        $job->seedTasks();   // re-seeding an existing job is a no-op for who has what

        $this->assertSame($this->sourcing->id, $this->whoHas($job)['Material Sourcing']);
        $this->assertSame($this->quality->id, $this->whoHas($job)['Stitching']);
        $this->assertSame(6, $job->tasks()->count());
    }

    public function test_a_job_with_no_customer_order_goes_to_whoever_raised_it(): void
    {
        $this->set('Information Desk', 'order_creator')->assertOk();

        $job = $this->job(servedBy: null, raisedBy: $this->server);
        $job->seedTasks();

        $this->assertSame($this->server->id, $this->whoHas($job)['Information Desk']);
    }

    public function test_someone_who_has_left_is_never_given_a_stage(): void
    {
        $this->theOwnersSetup();
        $this->sourcing->forceFill(['status' => 'suspended'])->save();
        $this->server->forceFill(['status' => 'inactive'])->save();

        $job = $this->job(servedBy: $this->server);
        $job->seedTasks();

        $who = $this->whoHas($job);
        $this->assertNull($who['Material Sourcing']);
        $this->assertNull($who['Laundry']);
        $this->assertNull($who['Information Desk']);
        $this->assertSame($this->quality->id, $who['Quality Check']);

        // And the setup list says so.
        $row = collect($this->getJson('/api/v1/admin/product-stages')->assertOk()->json())->firstWhere('name', 'Material Sourcing');
        $this->assertTrue($row['default_assignee_inactive']);
    }

    public function test_the_stage_list_says_who_does_each_stage(): void
    {
        $this->theOwnersSetup();

        $rows = collect($this->getJson('/api/v1/admin/product-stages')->assertOk()->json())->keyBy('name');

        $this->assertSame('order_creator', $rows['Payment']['default_assignee_mode']);
        $this->assertNull($rows['Payment']['default_assignee_name']);
        $this->assertSame('person', $rows['Quality Check']['default_assignee_mode']);
        $this->assertSame(trim("{$this->quality->first_name} {$this->quality->last_name}"), $rows['Quality Check']['default_assignee_name']);
        $this->assertSame('none', $rows['Stitching']['default_assignee_mode']);
        $this->assertFalse($rows['Quality Check']['default_assignee_inactive']);
        $this->assertArrayNotHasKey('default_assignee', $rows['Quality Check'], 'names only — no user record');
    }

    public function test_what_can_and_cannot_be_set(): void
    {
        $this->set('Laundry', 'person')->assertStatus(422);                       // nobody named
        $this->set('Laundry', 'sometimes')->assertStatus(422);                    // not a mode
        $customer = User::factory()->create(['status' => 'active', 'user_type' => 'customer']);
        $this->set('Laundry', 'person', $customer)->assertStatus(422);            // not staff
        $this->assertSame('none', $this->stage['Laundry']->fresh()->default_assignee_mode);

        // Renaming a stage does not disturb who does it.
        $this->set('Laundry', 'person', $this->sourcing)->assertOk();
        $this->putJson("/api/v1/admin/product-stages/{$this->stage['Laundry']->id}", ['name' => 'Laundry & Pressing'])->assertOk();
        $fresh = $this->stage['Laundry']->fresh();
        $this->assertSame('person', $fresh->default_assignee_mode);
        $this->assertSame($this->sourcing->id, $fresh->default_assignee_id);

        // The change is on the audit trail, old and new.
        $this->assertTrue(DB::table('activity_log')->where('action', 'production_stage_updated')
            ->where('properties', 'like', '%default_assignee_after%')->exists());
    }

    public function test_only_whoever_configures_the_floor_may_set_it(): void
    {
        Sanctum::actingAs($this->server);

        $this->set('Laundry', 'person', $this->server)->assertForbidden();
        $this->assertSame('none', $this->stage['Laundry']->fresh()->default_assignee_mode);
    }

    public function test_each_person_is_told_once_and_not_whoever_is_doing_the_confirming(): void
    {
        $this->theOwnersSetup();
        Sanctum::actingAs($this->server);   // the one serving confirms the job

        $job = $this->job(servedBy: $this->server);
        $job->seedTasks();

        Notification::assertSentToTimes($this->sourcing, \App\Notifications\ProductionAssignedNotification::class, 1);
        Notification::assertSentToTimes($this->quality, \App\Notifications\ProductionAssignedNotification::class, 1);
        Notification::assertNotSentTo($this->server, \App\Notifications\ProductionAssignedNotification::class);
    }
}

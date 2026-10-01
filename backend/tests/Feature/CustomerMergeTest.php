<?php

namespace Tests\Feature;

use App\Console\Commands\MergeDuplicateCustomers;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Services\CustomerMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * One person registered twice becomes one record, with nothing lost and
 * everything reversible. Every table that points at a customer moves; the kept
 * record is completed, never overwritten; consent and blocks survive; the
 * merged record is archived with a note; the audit entry undoes it exactly.
 */
class CustomerMergeTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function customer(array $attrs = []): Customer
    {
        $this->n++;

        return Customer::create($attrs + [
            'customer_number' => "CM-{$this->n}", 'first_name' => 'Ann', 'last_name' => 'Wanjiru',
            'email' => "cm{$this->n}@example.test", 'phone' => '0722 123 456',
        ]);
    }

    private function orderFor(Customer $c): Order
    {
        return Order::create(['order_number' => 'CM-O-' . uniqid(), 'order_type' => 'pos', 'status' => 'completed',
            'payment_status' => 'paid', 'currency_code' => 'KES', 'subtotal' => 1000, 'total_amount' => 1000, 'customer_id' => $c->id]);
    }

    private function world(): array
    {
        $keep  = $this->customer(['loyalty_points' => 10, 'company' => null, 'notes' => 'Prefers size L']);
        $dupe  = $this->customer(['phone' => '+254722123456', 'loyalty_points' => 5, 'company' => 'St Paul Parish',
            'wa_marketing_opt_out' => true, 'notes' => 'Measured 2026-07', 'last_purchase_at' => now()->subDay()]);
        $dupe2 = $this->customer(['phone' => '254 722 123 456', 'first_name' => 'A.', 'status' => 'blocked']);
        $product = Product::factory()->create();

        $o1 = $this->orderFor($dupe); $o2 = $this->orderFor($dupe2); $mine = $this->orderFor($keep);
        $po = ProductionOrder::create(['order_number' => 'CM-PO-1', 'product_id' => $product->id, 'quantity' => 1, 'customer_id' => $dupe->id]);
        $addr = DB::table('addresses')->insertGetId(['customer_id' => $dupe->id, 'first_name' => 'Ann', 'last_name' => 'W',
            'address_line1' => 'Kenyatta Ave', 'city' => 'Nairobi', 'country_code' => 'KE', 'created_at' => now(), 'updated_at' => now()]);
        $touch = DB::table('channel_touchpoints')->insertGetId(['customer_id' => $dupe2->id, 'phone' => '254722123456', 'channel' => 'whatsapp',
            'created_at' => now(), 'updated_at' => now()]);

        return compact('keep', 'dupe', 'dupe2', 'o1', 'o2', 'mine', 'po', 'addr', 'touch');
    }

    public function test_everything_that_points_at_the_duplicates_moves_and_the_kept_record_is_completed(): void
    {
        $w = $this->world();
        $plan = app(CustomerMergeService::class)->plan($w['keep']->id, [$w['dupe']->id, $w['dupe2']->id]);
        $this->assertTrue($plan['ok'], implode('; ', $plan['problems']));
        $this->assertSame(2, $plan['moves']['orders']);

        app(CustomerMergeService::class)->merge($w['keep']->id, [$w['dupe']->id, $w['dupe2']->id]);

        foreach (['o1', 'o2', 'mine'] as $o) {
            $this->assertSame($w['keep']->id, $w[$o]->fresh()->customer_id, "order {$o} belongs to the kept record");
        }
        $this->assertSame($w['keep']->id, $w['po']->fresh()->customer_id);
        $this->assertSame($w['keep']->id, (int) DB::table('addresses')->where('id', $w['addr'])->value('customer_id'));
        $this->assertSame($w['keep']->id, (int) DB::table('channel_touchpoints')->where('id', $w['touch'])->value('customer_id'));

        $k = $w['keep']->fresh();
        $this->assertSame(15, $k->loyalty_points, 'points add up');
        $this->assertSame('St Paul Parish', $k->company, 'a blank is filled from the duplicate');
        $this->assertTrue((bool) $k->wa_marketing_opt_out, 'an opt-out on either record survives');
        $this->assertSame('blocked', $k->status, 'a block on either record survives');
        $this->assertStringContainsString('Prefers size L', $k->notes);
        $this->assertStringContainsString('[From CM-2] Measured 2026-07', $k->notes, 'notes kept, labelled');
        $this->assertNotNull($k->last_purchase_at);
        $this->assertSame('0722 123 456', $k->phone, 'the kept record keeps its own phone');

        foreach (['dupe', 'dupe2'] as $d) {
            $c = Customer::withTrashed()->find($w[$d]->id);
            $this->assertNotNull($c->deleted_at, "{$d} archived, not deleted");
            $this->assertStringStartsWith('Merged into CM-1', $c->notes);
        }
        $entry = DB::table('activity_log')->where('event', 'customer_merged')->where('subject_id', $w['keep']->id)->first();
        $this->assertNotNull($entry, 'the undo record exists');
        $this->assertNotSame('[REDACTED]', json_decode($entry->properties, true)['merge_ref'], 'the reference was not redacted');
        $this->assertTrue(DB::table('activity_log')->where('subject_type', Order::class)->where('subject_id', $w['o1']->id)
            ->where('event', 'updated')->exists(), 'each moved order is in the audit trail');
    }

    public function test_unmerge_puts_everything_back_exactly(): void
    {
        $w = $this->world();
        $keepBefore = $w['keep']->fresh()->only(['loyalty_points', 'company', 'wa_marketing_opt_out', 'status', 'notes']);
        app(CustomerMergeService::class)->merge($w['keep']->id, [$w['dupe']->id, $w['dupe2']->id]);
        $entry = DB::table('activity_log')->where('event', 'customer_merged')->value('id');

        $this->artisan('customers:unmerge', ['entry' => $entry])->assertSuccessful();

        $this->assertSame($w['dupe']->id, $w['o1']->fresh()->customer_id);
        $this->assertSame($w['dupe2']->id, $w['o2']->fresh()->customer_id);
        $this->assertSame($w['keep']->id, $w['mine']->fresh()->customer_id);
        $this->assertSame($w['dupe']->id, $w['po']->fresh()->customer_id);
        $this->assertSame($w['dupe']->id, (int) DB::table('addresses')->where('id', $w['addr'])->value('customer_id'));
        $this->assertSame($w['dupe2']->id, (int) DB::table('channel_touchpoints')->where('id', $w['touch'])->value('customer_id'));
        $this->assertNull(Customer::find($w['dupe']->id)->deleted_at, 'restored');
        $this->assertSame('Measured 2026-07', Customer::find($w['dupe']->id)->notes, 'its own notes back, no merge label');
        $this->assertEquals($keepBefore, $w['keep']->fresh()->only(array_keys($keepBefore)), 'kept record as it was');
    }

    public function test_a_login_moves_with_its_person_and_two_logins_are_refused(): void
    {
        $keep = $this->customer();
        $withLogin = $this->customer(['phone' => '+254722123456', 'user_id' => User::factory()->create()->id]);
        $loginId = $withLogin->user_id;

        app(CustomerMergeService::class)->merge($keep->id, [$withLogin->id]);
        $this->assertSame($loginId, $keep->fresh()->user_id, 'the login now opens the kept record');
        $this->assertNull(Customer::withTrashed()->find($withLogin->id)->user_id);

        $a = $this->customer(['phone' => '0733 456 789', 'user_id' => User::factory()->create()->id]);
        $b = $this->customer(['phone' => '0733 456 789', 'user_id' => User::factory()->create()->id]);
        $plan = app(CustomerMergeService::class)->plan($a->id, [$b->id]);
        $this->assertFalse($plan['ok']);
        $this->assertStringContainsString('more than one of these records has a login', implode(' ', $plan['problems']));
    }

    public function test_a_plan_that_does_not_hold_is_refused_and_changes_nothing(): void
    {
        $keep = $this->customer();
        $other = $this->customer(['phone' => '0733 999 111']);
        $gone = $this->customer(['phone' => '0722 123 456']);
        $gone->delete();

        $svc = app(CustomerMergeService::class);
        $this->assertStringContainsString('does not share', implode(' ', $svc->plan($keep->id, [$other->id])['problems']));
        $this->assertStringContainsString('already archived', implode(' ', $svc->plan($keep->id, [$gone->id])['problems']));
        $this->assertStringContainsString('more than once', implode(' ', $svc->plan($keep->id, [$keep->id])['problems']));

        $this->expectException(\RuntimeException::class);
        try {
            $svc->merge($keep->id, [$other->id]);
        } finally {
            $this->assertNull($other->fresh()->deleted_at, 'nothing was archived');
        }
    }

    public function test_the_owner_s_pasted_list_is_read_and_a_dry_run_writes_nothing(): void
    {
        $text = "Merge these duplicate customers (2 groups):\nGroup 1 (+254724351780): keep #219, merge #73, #74, #341\nGroup 10 (+254700000009): keep #98, merge #178\n";
        $this->assertSame([
            ['group' => 1, 'keep' => 219, 'merge' => [73, 74, 341]],
            ['group' => 10, 'keep' => 98, 'merge' => [178]],
        ], MergeDuplicateCustomers::parse($text));

        $w = $this->world();
        $file = tempnam(sys_get_temp_dir(), 'merge');
        file_put_contents($file, "Group 1 (+254722123456): keep #{$w['keep']->id}, merge #{$w['dupe']->id}, #{$w['dupe2']->id}");

        $this->artisan('customers:merge', ['file' => $file])->expectsOutputToContain('DRY RUN')->assertSuccessful();
        $this->assertSame($w['dupe']->id, $w['o1']->fresh()->customer_id, 'a dry run moves nothing');
        $this->assertNull($w['dupe']->fresh()->deleted_at);

        $this->artisan('customers:merge', ['file' => $file, '--execute' => true])->assertFailed();   // needs --causer
        $causer = User::factory()->create();
        $this->artisan('customers:merge', ['file' => $file, '--execute' => true, '--causer' => $causer->id])->assertSuccessful();
        $this->assertSame($w['keep']->id, $w['o1']->fresh()->customer_id);
        $this->assertSame($causer->id, (int) DB::table('activity_log')->where('event', 'customer_merged')->value('causer_id'), 'the audit trail names who merged');
    }
}

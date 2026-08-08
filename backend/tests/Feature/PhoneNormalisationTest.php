<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\FreezesClockMidMonth;
use Tests\TestCase;

/**
 * normalize_phone() — one definition of "the same person's number".
 *
 * The two rules under test are the ones the production data taught us:
 *   1. normalise to full E.164, never match on trailing digits (this dataset
 *      spans nine country codes, and trailing-digit matching collides);
 *   2. anything that is not a plausible phone returns NULL rather than becoming
 *      a shared bucket — 26 orders carry clerk notes in customer_phone, and
 *      merging them into one "customer" is worse than counting them twice.
 */
class PhoneNormalisationTest extends TestCase
{
    use RefreshDatabase;
    use FreezesClockMidMonth;

    private function norm(?string $raw): ?string
    {
        return DB::selectOne('SELECT normalize_phone(?) AS v', [$raw])->v;
    }

    public function test_the_same_kenyan_number_normalises_to_one_value(): void
    {
        $expected = '+254724351780';

        foreach ([
            '0724351780',        // national, as a POS clerk types it
            '+254724351780',     // E.164
            '254724351780',      // international without the plus
            '724351780',         // bare subscriber number
            '0724 351 780',      // spaced
            ' 0724-351-780 ',    // punctuated and padded
        ] as $variant) {
            $this->assertSame($expected, $this->norm($variant), "failed for: {$variant}");
        }
    }

    public function test_foreign_numbers_keep_their_own_country_code(): void
    {
        // Kenya must never be assumed onto a number that already carries a
        // country code — this dataset holds +250, +257, +243, +255, +39, +973,
        // +211, +44 and +263.
        $this->assertSame('+97336363982', $this->norm('+97336363982'));
        $this->assertSame('+447401182700', $this->norm('+44 7401 182700'));
        $this->assertSame('+393248827122', $this->norm('+39 3248827122'));
    }

    public function test_two_different_countries_do_not_collide(): void
    {
        // The reason for full-E.164 matching rather than "last 9 digits".
        $this->assertNotSame($this->norm('+254712345678'), $this->norm('+255712345678'));
    }

    public function test_clerk_notes_are_not_a_customer(): void
    {
        // Real values found in production's customer_phone column.
        foreach ([
            'refer to IANDM', 'Via Mpesa', 'Paid in cash', 'bank transfer',
            'cash', 'I&m bank', 'Mpesa', 'REFER TO IANDM', '', '   ', '-',
        ] as $junk) {
            $this->assertNull($this->norm($junk), "'{$junk}' was treated as a phone number");
        }
    }

    public function test_a_customer_paying_twice_under_two_formats_counts_once(): void
    {
        $this->viewer();
        Order::factory()->create(['user_id' => null, 'customer_phone' => '0724351780',    'created_at' => now()]);
        Order::factory()->create(['user_id' => null, 'customer_phone' => '+254724351780', 'created_at' => now()]);
        Order::factory()->create(['user_id' => null, 'customer_phone' => '0798238300',    'created_at' => now()]);

        $this->assertSame(2, $this->uniqueCustomers());
    }

    public function test_orders_carrying_clerk_notes_do_not_merge_into_one_customer(): void
    {
        // The failure mode a naive normaliser introduces: every note collapses
        // to the same empty key, so unrelated walk-ins become one person.
        $this->viewer();
        Order::factory()->create(['user_id' => null, 'customer_phone' => 'refer to IANDM', 'customer_email' => null, 'created_at' => now()]);
        Order::factory()->create(['user_id' => null, 'customer_phone' => 'Paid in cash',   'customer_email' => null, 'created_at' => now()]);
        Order::factory()->create(['user_id' => null, 'customer_phone' => '0798238300',     'customer_email' => null, 'created_at' => now()]);

        // Only the one identifiable customer is counted. The two note-carrying
        // orders are UNCOUNTED, not counted as a single shared person.
        $this->assertSame(1, $this->uniqueCustomers());
    }

    private function viewer(): void
    {
        $u = User::factory()->create(['user_type' => 'staff']);
        $u->assignRole(Role::findOrCreate('admin', 'sanctum'));
        $u->givePermissionTo(Permission::findOrCreate('reports.view', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($u);
    }

    private function uniqueCustomers(): int
    {
        return (int) $this->getJson('/api/v1/admin/reports/sales/summary'
            .'?start_date='.now()->subDays(7)->format('Y-m-d')
            .'&end_date='.now()->format('Y-m-d'))
            ->assertOk()
            ->json('summary.unique_customers');
    }
}

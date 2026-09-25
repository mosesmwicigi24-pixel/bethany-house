<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A phone a form accepts must fit the column it lands in.
 *
 * 2026-09-25: a cashier put "Cooperative Bank of Kenya" in the phone field of a
 * POS order. Twenty-five characters — validation allows thirty, the column held
 * twenty, so it passed validation and died in the INSERT, taking the whole order
 * with it. Eight retries before she shortened it and got through.
 *
 * Two things are pinned here: the columns are wide enough for what the forms
 * accept, and a long phone reaches the database instead of throwing.
 */
class PhoneColumnWidthTest extends TestCase
{
    use RefreshDatabase;

    /** Every column a customer-facing phone field writes to. */
    private const COLUMNS = [
        ['customers', 'phone'],
        ['users', 'phone'],
        ['suppliers', 'phone'],
        ['outlets', 'phone'],
        ['addresses', 'phone'],
        ['user_addresses', 'phone'],
        ['orders', 'customer_phone'],
        ['quotations', 'customer_phone'],
        ['payments', 'phone_number'],
    ];

    /** The longest a validation rule lets through on any of these paths. */
    private const WIDEST_RULE = 32;

    public function test_every_phone_column_holds_what_the_forms_accept(): void
    {
        foreach (self::COLUMNS as [$table, $column]) {
            $length = DB::table('information_schema.columns')
                ->where('table_schema', 'public')->where('table_name', $table)->where('column_name', $column)
                ->value('character_maximum_length');

            $this->assertNotNull($length, "{$table}.{$column} is missing");
            $this->assertGreaterThanOrEqual(
                self::WIDEST_RULE,
                (int) $length,
                "{$table}.{$column} holds {$length} characters but a form will accept " . self::WIDEST_RULE
                . " — the overflow surfaces as a failed save, not a validation message",
            );
        }
    }

    public function test_a_phone_as_long_as_the_forms_allow_is_stored(): void
    {
        // The exact value that broke the POS, and a full 32 for the ceiling.
        foreach (['Cooperative Bank of Kenya', str_repeat('0', 32)] as $phone) {
            $customer = Customer::create([
                'first_name' => 'Boniface',
                'last_name'  => '',
                'phone'      => $phone,
                'email'      => 'boniface+' . strlen($phone) . '@example.test',
            ]);

            $this->assertDatabaseHas('customers', ['id' => $customer->id, 'phone' => $phone]);
        }
    }
}

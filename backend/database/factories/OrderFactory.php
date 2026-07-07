<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        // Only the columns that are NOT NULL without a DB default are set here;
        // user_id/outlet_id are nullable and deposit_amount defaults to null
        // (a non-deposit order). Defaults produce a fully-paid-looking POS order.
        return [
            'order_number'   => 'ORD-' . fake()->unique()->numerify('########'),
            'order_type'     => 'pos',
            'status'         => 'completed',
            'currency_code'  => 'KES',
            'subtotal'       => 1000,
            'total_amount'   => 1000,
            'payment_status' => 'pending',
        ];
    }
}

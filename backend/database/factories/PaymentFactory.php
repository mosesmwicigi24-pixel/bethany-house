<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        // Defaults to a settled ('paid') payment so it counts toward
        // Order::totalPaid() without extra state in the common case.
        return [
            'order_id'       => Order::factory(),
            'payment_number' => 'PAY-' . fake()->unique()->numerify('########'),
            'payment_method' => 'cash',
            'amount'         => 1000,
            'currency_code'  => 'KES',
            'status'         => 'paid',
            'refund_amount'  => 0,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        // customer_number and the placeholder email are filled by the model's
        // creating() hook, so only the meaningful fields are set here.
        return [
            'first_name'    => fake()->firstName(),
            'last_name'     => fake()->lastName(),
            'phone'         => '+2547' . fake()->numerify('########'),
            'customer_type' => 'individual',
            'status'        => 'active',
        ];
    }
}

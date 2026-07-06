<?php

namespace Database\Factories;

use App\Models\Outlet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Outlet>
 */
class OutletFactory extends Factory
{
    protected $model = Outlet::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('OUT-####'),
            'name' => fake()->company().' Store',
            'outlet_type' => 'store',
        ];
    }
}

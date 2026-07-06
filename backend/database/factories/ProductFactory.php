<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'name' => ucwords(fake()->words(2, true)),
            'sku' => fake()->unique()->bothify('SKU-#####'),
            'slug' => fake()->unique()->slug(),
        ];
    }
}

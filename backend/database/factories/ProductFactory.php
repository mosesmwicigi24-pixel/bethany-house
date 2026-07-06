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
        // NB: products has no `name` column — display names live in
        // product_translations (the catalogue is multi-language).
        return [
            'uuid' => (string) Str::uuid(),
            'sku' => fake()->unique()->bothify('SKU-#####'),
            'slug' => fake()->unique()->slug(),
        ];
    }
}

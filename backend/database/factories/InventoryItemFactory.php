<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\Outlet;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    protected $model = InventoryItem::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'product_variant_id' => null,
            'outlet_id' => Outlet::factory(),
            'quantity_on_hand' => 100,
            'quantity_reserved' => 0,
            'reorder_point' => 5,
            'reorder_quantity' => 10,
        ];
    }
}

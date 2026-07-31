<?php
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductTranslation;

$make = function (array $attrs, string $name, array $prices) {
    $p = Product::updateOrCreate(['slug' => $attrs['slug']], array_merge([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'status' => 'active',
        'published_at' => now()->subDay(),
        'product_type' => 'simple',
    ], $attrs));
    ProductTranslation::updateOrCreate(
        ['product_id' => $p->id, 'language_code' => 'en'],
        ['name' => $name],
    );
    foreach ($prices as $code => $amount) {
        ProductPrice::updateOrCreate(
            ['product_id' => $p->id, 'product_variant_id' => null, 'currency_code' => $code],
            ['regular_price' => $amount],
        );
    }
    echo "seeded {$p->slug} (#{$p->id})\n";
};

$make([
    'slug' => 'preaching-gown',
    'sku' => 'BH-GOWN-1',
    'is_producible' => true,
    'measurements' => [
        ['name' => 'Neck', 'unit' => 'in', 'required' => true],
        ['name' => 'Shoulders', 'unit' => 'in', 'required' => true],
        ['name' => 'Sleeves', 'unit' => 'in', 'required' => true],
        ['name' => 'Chest', 'unit' => 'in', 'required' => true],
        ['name' => 'Waist', 'unit' => 'in', 'required' => false],
        ['name' => 'Hips', 'unit' => 'in', 'required' => false],
        ['name' => 'Full Length', 'unit' => 'in', 'required' => true],
    ],
], 'Premium Preaching Gown — Tailored', ['KES' => 12500, 'USD' => 96]);

$make([
    'slug' => 'altar-wine',
    'sku' => 'BH-WINE-1',
    'is_producible' => false,
], 'Altar Wine — 750ml', ['KES' => 1200, 'USD' => 12]);

echo "customers: " . \App\Models\Customer::count() . ", orders: " . \App\Models\Order::count() . "\n";

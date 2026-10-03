<?php

namespace App\Services\Approvals\Handlers;

use Illuminate\Support\Facades\DB;

/** "Cassock — Large (CS-01-L) · KES": what a product_prices row is called in the inbox. */
final class PriceRowLabel
{
    public static function of(int $priceId): string
    {
        $row = DB::table('product_prices')->where('id', $priceId)->first();
        if (!$row) {
            return "Price #{$priceId}";
        }
        $product = DB::table('products')->where('id', $row->product_id)->first(['id', 'sku']);
        $name = DB::table('product_translations')->where('product_id', $row->product_id)
            ->orderByRaw("language_code = 'en' DESC")->value('name');
        $variant = $row->product_variant_id
            ? DB::table('product_variants')->where('id', $row->product_variant_id)->first(['sku', 'variant_name'])
            : null;

        $title = $name ?: ($product->sku ?? "Product #{$row->product_id}");
        if ($variant?->variant_name) {
            $title .= " — {$variant->variant_name}";
        }
        $sku = $variant->sku ?? $product->sku ?? null;

        return $title . ($sku ? " ({$sku})" : '') . ' · ' . strtoupper((string) $row->currency_code);
    }
}

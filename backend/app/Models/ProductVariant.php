<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'sku',
        'variant_name',
        'attributes',
        'weight',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'attributes' => 'array',
        'weight' => 'decimal:2',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Compose a merchandising variant name from the product and its attributes.
     *
     * The COLOUR is the headline — it leads with the garment base name — and the
     * remaining attributes become a plain-language explanation after a "+".
     *
     *   Princes Cassock (– Blue) + {Colour: White, Piping/Buttons/Pleats: Black}
     *     → "White Princes Cassock + Black Piping, Buttons and Pleats"
     *   {Colour: White, Trim: Black}
     *     → "White Princes Cassock + Black"        (a lone trim shows just its value)
     *   {Colour: White, Piping: Black, Buttons: Gold}
     *     → "White Princes Cassock + Black Piping, Gold Buttons"
     *
     * Attributes sharing a value are grouped ("Black Piping, Buttons and Pleats");
     * the garment base is the product name with a trailing spaced-dash colour
     * suffix stripped ("Princes Cassock – Blue" → "Princes Cassock") so the
     * variant colour, not the product's, leads.
     */
    public static function composeName(string $productName, array $attributes): string
    {
        // Base garment: drop a trailing " – Blue" / " - Blue" / " — Blue" suffix,
        // but never a hyphen inside a word ("T-Shirt" is safe — spaces required).
        $base = preg_replace('/\s+[\x{2013}\x{2014}-]\s+\S.*$/u', '', $productName);
        $base = trim((string) $base) !== '' ? trim((string) $base) : trim($productName);

        // Keep non-empty string attributes, preserving insertion order — and
        // drop SIZE entirely: size is a pick-your-size option, not part of the
        // merchandising name (a Black cassock is "…Black Piping…", the size is
        // chosen separately).
        $attrs = [];
        foreach ($attributes as $k => $v) {
            if (is_string($v) && trim($v) !== '' && !self::isSizeKey($k)) {
                $attrs[$k] = trim($v);
            }
        }
        if (empty($attrs)) {
            return $base;
        }

        // Features role: when a "Features" attribute lists the parts (Piping,
        // Pleats, Buttons…), the COLOUR colours those features and the garment
        // leads by its own name — "White Princes Cassock + Black Piping, Pleats
        // and Buttons" (the colour explains the features; it does not re-lead a
        // garment whose colour is already in its name).
        $featuresKey = null;
        foreach (array_keys($attrs) as $k) {
            if (in_array(strtolower(trim($k)), ['features', 'feature'], true)) {
                $featuresKey = $k;
                break;
            }
        }
        if ($featuresKey !== null) {
            $colour = '';
            foreach ($attrs as $k => $v) {
                if (in_array(strtolower(trim($k)), ['colour', 'color'], true)) {
                    $colour = $v;
                    break;
                }
            }
            $coloured = trim($colour . ' ' . $attrs[$featuresKey]);
            // Any remaining attributes (not colour, not features) trail as trim.
            $extra = [];
            foreach ($attrs as $k => $v) {
                $lk = strtolower(trim($k));
                if ($lk === strtolower(trim($featuresKey)) || in_array($lk, ['colour', 'color'], true)) {
                    continue;
                }
                $extra[] = $v;
            }
            $suffix = implode(', ', array_merge([$coloured], $extra));
            return trim($base . ' + ' . $suffix);
        }

        // Headline colour: an attribute named colour/color, else the first.
        $mainKey = null;
        foreach (array_keys($attrs) as $k) {
            if (in_array(strtolower(trim($k)), ['colour', 'color'], true)) {
                $mainKey = $k;
                break;
            }
        }
        $mainKey ??= array_key_first($attrs);
        // Don't repeat the garment when the colour value already names it:
        // "BLACK PREACHING GOWN" for a Preaching Gown stays as-is, it doesn't
        // become "BLACK PREACHING GOWN Preaching Gown".
        $leadValue = trim($attrs[$mainKey]);
        $lead = self::valueAlreadyNamesGarment($leadValue, $base)
            ? $leadValue
            : trim($leadValue . ' ' . $base);

        // Secondary attributes, grouped by shared value (first-seen order).
        $groups = [];
        foreach ($attrs as $label => $value) {
            if ($label === $mainKey) {
                continue;
            }
            $groups[$value][] = $label;
        }
        if (empty($groups)) {
            return $lead;
        }

        $parts = [];
        foreach ($groups as $value => $labels) {
            // A single lone trim attribute reads as just its colour ("+ Black");
            // anything richer carries its labels ("Black Piping, Buttons and Pleats").
            if (count($groups) === 1 && count($labels) === 1) {
                $parts[] = $value;
            } else {
                $parts[] = trim($value . ' ' . self::joinWithAnd($labels));
            }
        }

        return $lead . ' + ' . implode(', ', $parts);
    }

    /** Size / Sizes attribute — excluded from the merchandising name. */
    private static function isSizeKey(string $key): bool
    {
        return in_array(strtolower(trim($key)), ['size', 'sizes'], true);
    }

    /**
     * True when the colour value already contains the garment — the whole base
     * name ("… Preaching Gown"), or its final garment word as a whole word
     * ("… Gown") — so re-appending the base would just repeat it.
     */
    private static function valueAlreadyNamesGarment(string $value, string $base): bool
    {
        $v = mb_strtolower($value);
        $b = mb_strtolower(trim($base));
        if ($b !== '' && str_contains($v, $b)) {
            return true;
        }
        $words = preg_split('/\s+/', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $last = end($words);
        return $last !== false
            && mb_strlen($last) >= 4
            && preg_match('/\b' . preg_quote($last, '/') . '\b/u', $v) === 1;
    }

    /** ["Piping","Buttons","Pleats"] → "Piping, Buttons and Pleats". */
    private static function joinWithAnd(array $items): string
    {
        $items = array_values($items);
        $n = count($items);
        if ($n === 0) return '';
        if ($n === 1) return $items[0];
        if ($n === 2) return $items[0] . ' and ' . $items[1];
        return implode(', ', array_slice($items, 0, -1)) . ' and ' . $items[$n - 1];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function prices()
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class);
    }

    public function inventoryItems()
    {
        return $this->hasMany(InventoryItem::class);
    }

    /**
     * Inventory rows with no specific outlet - warehouse/global stock fallback.
     */
    public function warehouseInventoryItems()
    {
        return $this->hasMany(InventoryItem::class)->whereNull('outlet_id');
    }
}
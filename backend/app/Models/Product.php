<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    /*
    |--------------------------------------------------------------------------
    | Mass Assignment
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'uuid',
        'category_id',
        'sku',
        'slug',
        'product_type',
        'is_producible',
        'status',
        'published_at',
        'is_featured',
        'weight',
        'length',
        'width',
        'height',
        'brand',
        'tax_class',
        'low_stock_threshold',
        'sort_order',
        'measurements',
    ];

    /*
    |--------------------------------------------------------------------------
    | Casts
    |--------------------------------------------------------------------------
    */

    protected $casts = [
        'is_producible' => 'boolean',
        'is_featured' => 'boolean',
        'weight' => 'decimal:2',
        'length' => 'decimal:2',
        'width' => 'decimal:2',
        'height' => 'decimal:2',
        'low_stock_threshold' => 'integer',
        'sort_order' => 'integer',
        'published_at' => 'datetime',
        'deleted_at' => 'datetime',
        'measurements' => 'array',
    ];

    /*
    |--------------------------------------------------------------------------
    | Status Constants (Clean Architecture)
    |--------------------------------------------------------------------------
    */

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ARCHIVED = 'archived';

    /*
    |--------------------------------------------------------------------------
    | Boot
    |--------------------------------------------------------------------------
    */

    protected static function booted(): void
    {
        static::creating(function ($product) {
            if (empty($product->uuid)) {
                $product->uuid = (string) Str::uuid();
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function translations()
    {
        return $this->hasMany(ProductTranslation::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function prices()
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class);
    }

    public function seo()
    {
        return $this->hasMany(ProductSeo::class);
    }

    public function reviews()
    {
        return $this->hasMany(ProductReview::class);
    }

    public function inventoryItems()
    {
        return $this->hasMany(InventoryItem::class);
    }

    /**
     * Inventory rows with no specific outlet - represents warehouse/global stock.
     * Used as fallback when no outlet-specific row exists.
     */
    public function warehouseInventoryItems()
    {
        return $this->hasMany(InventoryItem::class)->whereNull('outlet_id');
    }

    public function billOfMaterials()
    {
        return $this->hasMany(BillOfMaterial::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes (Business Logic Encapsulated)
    |--------------------------------------------------------------------------
    */

    // Only active (regardless of publish date)
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    // Visible in storefront
    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeSimple($query)
    {
        return $query->where('product_type', 'simple');
    }

    public function scopeVariant($query)
    {
        return $query->where('product_type', 'variant');
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    public function isPublished(): bool
    {
        return $this->isActive() &&
            $this->published_at &&
            $this->published_at->isPast();
    }

    public function getTranslation($languageCode = 'en')
    {
        return $this->translations()
            ->where('language_code', $languageCode)
            ->first();
    }

    public function getPriceForCurrency($currencyCode = 'KES', $variantId = null)
    {
        $query = $this->prices()
            ->where('currency_code', $currencyCode);

        if ($variantId) {
            $query->where('product_variant_id', $variantId);
        } else {
            $query->whereNull('product_variant_id');
        }

        return $query->first();
    }

    public function getTotalStock()
    {
        return $this->inventoryItems()
            ->sum('quantity_on_hand');
    }

    public function getAvailableStock()
    {
        return $this->inventoryItems()
            ->sum(DB::raw('quantity_on_hand - quantity_reserved'));
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Category extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'parent_id',
        'slug',
        'name_en', 'name_sw', 'name_fr', 'name_pt',
        'description_en', 'description_sw', 'description_fr', 'description_pt',
        'image_url',
        'icon',
        'color',
        'sort_order',
        'is_active',
        'show_in_menu',
        'show_in_storefront',
        'featured',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'products_count',
    ];

    protected $casts = [
        'is_active'           => 'boolean',
        'show_in_menu'        => 'boolean',
        'show_in_storefront'  => 'boolean',
        'featured'            => 'boolean',
        'sort_order'          => 'integer',
        'products_count'      => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id')
            ->orderBy('sort_order');
    }

    public function allChildren()
    {
        return $this->hasMany(Category::class, 'parent_id')
            ->with('allChildren')
            ->orderBy('sort_order');
    }

    public function translations()
    {
        return $this->hasMany(CategoryTranslation::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeRootLevel($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeFeatured($query)
    {
        return $query->where('featured', true);
    }

    public function scopeInMenu($query)
    {
        return $query->where('show_in_menu', true);
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    /**
     * Get the display name in the requested language, falling back to English.
     */
    public function getName(string $lang = 'en'): string
    {
        $field = "name_{$lang}";
        return $this->{$field} ?? $this->name_en ?? '';
    }

    /**
     * Get description in the requested language, falling back to English.
     */
    public function getDescription(string $lang = 'en'): ?string
    {
        $field = "description_{$lang}";
        return $this->{$field} ?? $this->description_en;
    }

    /**
     * Full breadcrumb path e.g. "Fashion > Women > Dresses"
     */
    public function getBreadcrumbAttribute(): string
    {
        $parts = [$this->name_en];
        $parent = $this->parent;

        while ($parent) {
            array_unshift($parts, $parent->name_en);
            $parent = $parent->parent;
        }

        return implode(' > ', $parts);
    }

    /**
     * Image URL with fallback.
     */
    public function getImageAttribute(): ?string
    {
        return $this->image_url
            ? (str_starts_with($this->image_url, 'http') ? $this->image_url : asset('storage/' . $this->image_url))
            : null;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Check for circular reference when setting parent_id.
     */
    public function wouldCreateCircularReference(int $newParentId): bool
    {
        if ($newParentId === $this->id) {
            return true;
        }

        $parent = Category::find($newParentId);
        while ($parent) {
            if ($parent->id === $this->id) {
                return true;
            }
            $parent = $parent->parent;
        }

        return false;
    }

    /**
     * Get all descendant IDs (for filtering products in a tree).
     */
    public function getAllDescendantIds(): array
    {
        $ids = [];
        $children = $this->children()->select('id')->get();

        foreach ($children as $child) {
            $ids[] = $child->id;
            $ids = array_merge($ids, (new self(['id' => $child->id]))->getAllDescendantIds());
        }

        return $ids;
    }

    /**
     * Auto-generate slug from name_en if not provided.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($category) {
            if (empty($category->slug) && !empty($category->name_en)) {
                $slug = Str::slug($category->name_en);
                $original = $slug;
                $i = 1;
                while (static::where('slug', $slug)->exists()) {
                    $slug = "{$original}-{$i}";
                    $i++;
                }
                $category->slug = $slug;
            }
        });
    }
}
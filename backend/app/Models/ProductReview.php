<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductReview extends Model
{
    use HasFactory;

    /** Written by a hub user (user_id set). */
    public const SOURCE_HUB = 'hub';
    /** Written by a customer on the storefront — author_name / author_email, no user (HUB_CONTRACT §8). */
    public const SOURCE_STOREFRONT = 'storefront';

    protected $fillable = [
        'product_id',
        'user_id',
        'order_id',
        'author_name',
        'author_email',
        'client_request_id',
        'source',
        'rating',
        'title',
        'review',
        'is_verified_purchase',
        'is_approved',
        'helpful_count',
    ];

    protected $casts = [
        'rating' => 'integer',
        'is_verified_purchase' => 'boolean',
        'is_approved' => 'boolean',
        'helpful_count' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The name shown beside a review: the hub user's, else the name the
     * storefront reviewer typed, else "Customer". Never the email.
     */
    public function getAuthorLabelAttribute(): string
    {
        $name = trim((string) ($this->user?->name ?? ''));
        if ($name === '') {
            $name = trim((string) $this->author_name);
        }
        return $name !== '' ? $name : 'Customer';
    }

    public function scopeApproved($query)
    {
        return $query->where('is_approved', true);
    }

    public function scopeVerifiedPurchase($query)
    {
        return $query->where('is_verified_purchase', true);
    }
}

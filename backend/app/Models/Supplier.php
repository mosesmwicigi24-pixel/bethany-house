<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_code',
        'name',
        'type',
        'status',
        'supply_category',
        'contact_person',
        'email',
        'phone',
        'alternate_phone',
        'website',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country',
        'tax_id',
        'tax_number',
        'registration_number',
        'payment_terms',
        'credit_limit',
        'currency',
        'bank_name',
        'bank_account_number',
        'bank_account_name',
        'bank_swift_code',
        'rating',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'rating'       => 'decimal:2',
        'is_active'    => 'boolean',
        'credit_limit' => 'decimal:2',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function materials()
    {
        return $this->hasMany(Material::class);
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function returns()
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    public function scopeTopRated($query, $minRating = 4.0)
    {
        return $query->where('rating', '>=', $minRating);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('supply_category', $category);
    }

    // ─── Accessors ────────────────────────────────────────────────────────────

    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address_line_1,
            $this->address_line_2,
            $this->city,
            $this->state,
            $this->postal_code,
            $this->country,
        ]);

        return implode(', ', $parts);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->company_code
            ? "[{$this->company_code}] {$this->name}"
            : $this->name;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    public function getTotalPurchaseAmount($startDate = null, $endDate = null): float
    {
        $query = $this->purchaseOrders()
            ->where('status', '!=', 'cancelled');

        if ($startDate) {
            $query->where('order_date', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('order_date', '<=', $endDate);
        }

        return (float) $query->sum('total_amount');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
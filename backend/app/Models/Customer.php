<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'customer_number',
        'first_name',
        'last_name',
        'email',
        'phone',
        'date_of_birth',
        'gender',
        'company',
        'tax_id',
        'customer_type',
        'preferred_language',
        'preferred_currency',
        'credit_limit',
        'outstanding_balance',
        'loyalty_points',
        'status',
        'notes',
        'last_purchase_at',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'credit_limit' => 'decimal:2',
        'outstanding_balance' => 'decimal:2',
        'loyalty_points' => 'integer',
        'last_purchase_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($customer) {
            if (empty($customer->customer_number)) {
                $customer->customer_number = 'CUST-' . date('Ymd') . '-' . str_pad(static::whereDate('created_at', today())->count() + 1, 5, '0', STR_PAD_LEFT);
            }
            // Email is NOT NULL in the DB but is optional in the UI.
            // Generate a placeholder so walk-in / POS customers without an
            // email address can still be created without a schema change.
            if (empty($customer->email)) {
                $customer->email = 'noemail+' . ($customer->customer_number ?? \Illuminate\Support\Str::random(8)) . '@placeholder.local';
            }
            // last_name is NOT NULL in the DB but is optional in the UI -
            // same situation as email above. Walk-in POS customers are
            // often created with only a first name and phone number, which
            // previously threw SQLSTATE[23502] (not-null violation) on
            // every such sale, regardless of the cashier's role/permissions.
            // PosController's three Customer::create() call sites already
            // pass '' defensively, but this guard protects every other
            // creation path too (admin Customers page, customer
            // quick-create, imports, etc.) without a schema change.
            if (is_null($customer->last_name)) {
                $customer->last_name = '';
            }
        });
    }

    /**
     * Relationships
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function addresses()
    {
        return $this->hasMany(Address::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'customer_id');
    }

    public function carts()
    {
        return $this->hasMany(Cart::class);
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    public function scopeBlocked($query)
    {
        return $query->where('status', 'blocked');
    }

    public function scopeIndividual($query)
    {
        return $query->where('customer_type', 'individual');
    }

    public function scopeBusiness($query)
    {
        return $query->where('customer_type', 'business');
    }

    public function scopeWithOutstanding($query)
    {
        return $query->where('outstanding_balance', '>', 0);
    }

    /**
     * Accessors
     */
    public function getFullNameAttribute()
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    public function getAvailableCreditAttribute()
    {
        return max(0, $this->credit_limit - $this->outstanding_balance);
    }

    /**
     * Helper methods
     */
    public function hasAvailableCredit($amount)
    {
        return $this->available_credit >= $amount;
    }

    public function addLoyaltyPoints($points)
    {
        $this->increment('loyalty_points', $points);
        return $this;
    }

    public function redeemLoyaltyPoints($points)
    {
        if ($this->loyalty_points < $points) {
            throw new \Exception('Insufficient loyalty points');
        }

        $this->decrement('loyalty_points', $points);
        return $this;
    }

    public function addToOutstandingBalance($amount)
    {
        $this->increment('outstanding_balance', $amount);
        return $this;
    }

    public function deductFromOutstandingBalance($amount)
    {
        $this->decrement('outstanding_balance', $amount);
        return $this;
    }

    public function getTotalPurchaseAmount($startDate = null, $endDate = null)
    {
        $query = $this->orders()->where('status', 'completed');
        
        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }
        
        if ($endDate) {
            $query->where('created_at', '<=', $endDate);
        }
        
        return $query->sum('total_amount');
    }

    public function getTotalOrders()
    {
        return $this->orders()->count();
    }

    public function getAverageOrderValue()
    {
        $totalOrders = $this->getTotalOrders();
        
        if ($totalOrders === 0) {
            return 0;
        }
        
        return $this->orders()->sum('total_amount') / $totalOrders;
    }

    public function updateLastPurchaseDate()
    {
        $this->update(['last_purchase_at' => now()]);
        return $this;
    }
}
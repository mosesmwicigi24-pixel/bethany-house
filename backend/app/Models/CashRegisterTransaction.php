<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashRegisterTransaction extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'cash_register_id',
        'transaction_type',
        'payment_method',
        'amount',
        'balance_after',
        'order_id',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    /**
     * Relationships
     */
    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scopes
     */
    public function scopeSales($query)
    {
        return $query->where('transaction_type', 'sale');
    }

    public function scopeRefunds($query)
    {
        return $query->where('transaction_type', 'refund');
    }

    public function scopeCashTransactions($query)
    {
        return $query->whereIn('transaction_type', ['cash_in', 'cash_out']);
    }

    public function scopeByPaymentMethod($query, $method)
    {
        return $query->where('payment_method', $method);
    }
}

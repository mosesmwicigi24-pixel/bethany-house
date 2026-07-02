<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashRegister extends Model
{
    use HasFactory;

    protected $fillable = [
        'register_number',
        'outlet_id',
        'register_name',
        'status',
        'currency_code',
        'opening_balance',
        'closing_balance',
        'expected_cash',
        'actual_cash',
        'total_sales',
        'total_cash_sales',
        'total_card_sales',
        'total_mpesa_sales',
        'total_refunds',
        'transaction_count',
        'opened_by',
        'closed_by',
        'opened_at',
        'closed_at',
        'opening_notes',
        'closing_notes',
        'denomination_count',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'closing_balance' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'actual_cash' => 'decimal:2',
        'total_sales' => 'decimal:2',
        'total_cash_sales' => 'decimal:2',
        'total_card_sales' => 'decimal:2',
        'total_mpesa_sales' => 'decimal:2',
        'total_refunds' => 'decimal:2',
        'transaction_count' => 'integer',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'denomination_count' => 'array',
    ];

    protected $appends = ['cash_difference'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($register) {
            if (empty($register->register_number)) {
                $register->register_number = 'REG-' . $register->outlet_id . '-' . str_pad(static::where('outlet_id', $register->outlet_id)->count() + 1, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    /**
     * Relationships
     */
    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function openedBy()
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function transactions()
    {
        return $this->hasMany(CashRegisterTransaction::class);
    }

    /**
     * Scopes
     */
    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function scopeClosed($query)
    {
        return $query->where('status', 'closed');
    }

    public function scopeToday($query)
    {
        return $query->whereDate('opened_at', today());
    }

    public function scopeForOutlet($query, $outletId)
    {
        return $query->where('outlet_id', $outletId);
    }

    /**
     * Accessors
     */
    public function getCashDifferenceAttribute()
    {
        return $this->actual_cash - $this->expected_cash;
    }

    public function getNetSalesAttribute()
    {
        return $this->total_sales - $this->total_refunds;
    }

    /**
     * Helper methods
     */
    public function isOpen()
    {
        return $this->status === 'open';
    }

    public function isClosed()
    {
        return $this->status === 'closed';
    }

    public function open($openingBalance, $userId, $notes = null)
    {
        if ($this->isOpen()) {
            throw new \Exception('Cash register is already open');
        }

        $this->update([
            'status' => 'open',
            'opening_balance' => $openingBalance,
            'expected_cash' => $openingBalance,
            'opened_by' => $userId,
            'opened_at' => now(),
            'opening_notes' => $notes,
            // Reset counters
            'total_sales' => 0,
            'total_cash_sales' => 0,
            'total_card_sales' => 0,
            'total_mpesa_sales' => 0,
            'total_refunds' => 0,
            'transaction_count' => 0,
        ]);

        return $this;
    }

    public function close($actualCash, $userId, $notes = null, $denominationCount = null)
    {
        if (!$this->isOpen()) {
            throw new \Exception('Cash register is not open');
        }

        $this->update([
            'status' => 'closed',
            'actual_cash' => $actualCash,
            'closing_balance' => $actualCash,
            'closed_by' => $userId,
            'closed_at' => now(),
            'closing_notes' => $notes,
            'denomination_count' => $denominationCount,
        ]);

        return $this;
    }

    public function recordSale($amount, $paymentMethod, $orderId = null)
    {
        if (!$this->isOpen()) {
            throw new \Exception('Cash register is not open');
        }

        $this->increment('total_sales', $amount);
        $this->increment('transaction_count');

        switch (strtolower($paymentMethod)) {
            case 'cash':
                $this->increment('total_cash_sales', $amount);
                $this->increment('expected_cash', $amount);
                break;
            case 'card':
                $this->increment('total_card_sales', $amount);
                break;
            case 'mpesa':
            case 'm-pesa':
                $this->increment('total_mpesa_sales', $amount);
                break;
        }

        // Log transaction
        $this->transactions()->create([
            'transaction_type' => 'sale',
            'payment_method' => $paymentMethod,
            'amount' => $amount,
            'order_id' => $orderId,
            'balance_after' => $this->expected_cash,
        ]);

        return $this;
    }

    public function recordRefund($amount, $paymentMethod, $orderId = null)
    {
        if (!$this->isOpen()) {
            throw new \Exception('Cash register is not open');
        }

        $this->increment('total_refunds', $amount);

        if (strtolower($paymentMethod) === 'cash') {
            $this->decrement('expected_cash', $amount);
        }

        // Log transaction
        $this->transactions()->create([
            'transaction_type' => 'refund',
            'payment_method' => $paymentMethod,
            'amount' => -$amount,
            'order_id' => $orderId,
            'balance_after' => $this->expected_cash,
        ]);

        return $this;
    }

    public function recordCashIn($amount, $reason, $userId = null)
    {
        if (!$this->isOpen()) {
            throw new \Exception('Cash register is not open');
        }

        $this->increment('expected_cash', $amount);

        $this->transactions()->create([
            'transaction_type' => 'cash_in',
            'amount' => $amount,
            'notes' => $reason,
            'created_by' => $userId ?? auth()->id(),
            'balance_after' => $this->expected_cash,
        ]);

        return $this;
    }

    public function recordCashOut($amount, $reason, $userId = null)
    {
        if (!$this->isOpen()) {
            throw new \Exception('Cash register is not open');
        }

        if ($amount > $this->expected_cash) {
            throw new \Exception('Insufficient cash in register');
        }

        $this->decrement('expected_cash', $amount);

        $this->transactions()->create([
            'transaction_type' => 'cash_out',
            'amount' => -$amount,
            'notes' => $reason,
            'created_by' => $userId ?? auth()->id(),
            'balance_after' => $this->expected_cash,
        ]);

        return $this;
    }

    public function getShiftSummary()
    {
        return [
            'register_number' => $this->register_number,
            'outlet' => $this->outlet->name,
            'opened_by' => $this->openedBy->full_name ?? 'Unknown',
            'closed_by' => $this->closedBy->full_name ?? 'Unknown',
            'opened_at' => $this->opened_at,
            'closed_at' => $this->closed_at,
            'duration' => $this->opened_at && $this->closed_at 
                ? $this->closed_at->diffForHumans($this->opened_at, true) 
                : 'Still open',
            'opening_balance' => $this->opening_balance,
            'closing_balance' => $this->closing_balance,
            'total_sales' => $this->total_sales,
            'total_cash_sales' => $this->total_cash_sales,
            'total_card_sales' => $this->total_card_sales,
            'total_mpesa_sales' => $this->total_mpesa_sales,
            'total_refunds' => $this->total_refunds,
            'net_sales' => $this->net_sales,
            'transaction_count' => $this->transaction_count,
            'expected_cash' => $this->expected_cash,
            'actual_cash' => $this->actual_cash,
            'cash_difference' => $this->cash_difference,
            'status' => $this->cash_difference == 0 ? 'balanced' : ($this->cash_difference > 0 ? 'overage' : 'shortage'),
        ];
    }

    public function hasDiscrepancy($tolerance = 0)
    {
        return abs($this->cash_difference) > $tolerance;
    }

    public function getDenominationBreakdown()
    {
        if (!$this->denomination_count) {
            return null;
        }

        $total = 0;
        $breakdown = [];

        foreach ($this->denomination_count as $denomination => $count) {
            $value = $denomination * $count;
            $total += $value;
            $breakdown[] = [
                'denomination' => $denomination,
                'count' => $count,
                'value' => $value,
            ];
        }

        return [
            'breakdown' => $breakdown,
            'total' => $total,
            'matches_actual' => $total == $this->actual_cash,
        ];
    }
}

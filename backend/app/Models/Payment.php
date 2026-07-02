<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'payment_number',
        'payment_method',
        'amount',
        'currency_code',
        'status',
        'provider',
        'provider_transaction_id',
        'provider_reference',
        'provider_response',
        'phone_number',
        'cash_received',
        'change_given',
        'tax_inclusive',
        'tax_amount_collected',
        'proof_of_payment_path',
        'proof_uploaded_at',
        'requires_approval',
        'approval_status',
        'approved_by',
        'approved_at',
        'approval_notes',
        'refund_amount',
        'refunded_at',
        'void_reason',
        'voided_at',
        'voided_by',
        'paid_at',
    ];

    protected $casts = [
        'amount'               => 'decimal:2',
        'tax_amount_collected' => 'decimal:2',
        'refund_amount'        => 'decimal:2',
        'cash_received'        => 'decimal:2',
        'change_given'         => 'decimal:2',
        'tax_inclusive'        => 'boolean',
        'requires_approval'    => 'boolean',
        'provider_response'    => 'array',
        'proof_uploaded_at'    => 'datetime',
        'approved_at'          => 'datetime',
        'refunded_at'          => 'datetime',
        'voided_at'            => 'datetime',
        'paid_at'              => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($payment) {
            if (empty($payment->payment_number)) {
                $payment->payment_number = 'PAY-' . strtoupper(uniqid());
            }
        });
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function transactions()
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ── Approval helpers ──────────────────────────────────────────────────────

    public function needsApproval(): bool
    {
        return $this->requires_approval && $this->approval_status !== 'approved';
    }

    public function isApproved(): bool
    {
        return !$this->requires_approval || $this->approval_status === 'approved';
    }

    public function isPendingReview(): bool
    {
        return $this->requires_approval && $this->approval_status === 'pending_review';
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function isPaid()
    {
        return $this->status === 'paid';
    }

    public function isPending()
    {
        return $this->status === 'pending';
    }

    public function canBeRefunded()
    {
        return $this->isPaid() && $this->refund_amount < $this->amount;
    }

    public function getRemainingRefundableAmount()
    {
        return $this->amount - $this->refund_amount;
    }
}
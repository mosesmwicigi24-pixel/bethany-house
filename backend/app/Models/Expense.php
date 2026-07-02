<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference_number',
        'title',
        'description',
        'category_id',
        'amount',
        'currency_code',
        'exchange_rate',
        'amount_kes',
        'expense_date',
        'payment_method',
        'payment_reference',
        'vendor_name',
        'vendor_contact',
        'outlet_id',
        'department',
        'is_recurring',
        'recurrence_frequency',
        'recurrence_end_date',
        'parent_expense_id',
        'status',          // draft | pending_approval | approved | rejected | paid | cancelled
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'paid_by',
        'paid_at',
        'receipt_path',
        'notes',
        'tags',
        'purchase_order_id', // link to PO if expense relates to procurement
        'production_order_id',
        'order_id',
        'created_by',
    ];

    protected $casts = [
        'amount'             => 'decimal:2',
        'exchange_rate'      => 'decimal:6',
        'amount_kes'         => 'decimal:2',
        'expense_date'       => 'date',
        'is_recurring'       => 'boolean',
        'recurrence_end_date'=> 'date',
        'submitted_at'       => 'datetime',
        'approved_at'        => 'datetime',
        'rejected_at'        => 'datetime',
        'paid_at'            => 'datetime',
        'tags'               => 'array',
    ];

    // ── Boot ──────────────────────────────────────────────────────────────────

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($expense) {
            if (empty($expense->reference_number)) {
                $expense->reference_number = 'EXP-' . date('Ymd') . '-'
                    . str_pad(static::whereDate('created_at', today())->count() + 1, 4, '0', STR_PAD_LEFT);
            }
            if (empty($expense->status)) {
                $expense->status = 'draft';
            }
        });
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function paidBy()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function parentExpense()
    {
        return $this->belongsTo(Expense::class, 'parent_expense_id');
    }

    public function recurringChildren()
    {
        return $this->hasMany(Expense::class, 'parent_expense_id');
    }

    public function approvals()
    {
        return $this->hasMany(ExpenseApproval::class);
    }

    public function lineItems()
    {
        return $this->hasMany(ExpenseLineItem::class);
    }

    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', 'pending_approval');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopeForPeriod($query, $start, $end)
    {
        return $query->whereBetween('expense_date', [$start, $end]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function getIsPendingAttribute(): bool
    {
        return $this->status === 'pending_approval';
    }

    public function getIsApprovedAttribute(): bool
    {
        return in_array($this->status, ['approved', 'paid']);
    }

    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'draft'            => 'gray',
            'pending_approval' => 'yellow',
            'approved'         => 'blue',
            'paid'             => 'green',
            'rejected'         => 'red',
            'cancelled'        => 'gray',
            default            => 'gray',
        };
    }
}
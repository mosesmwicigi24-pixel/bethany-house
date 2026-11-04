<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/** A commission on top of salary: recorded → approved → paid (or void). */
class StaffCommission extends Model
{
    public const STATUSES = ['recorded', 'approved', 'paid', 'void'];

    protected $fillable = ['user_id', 'transaction_ref', 'order_id', 'amount', 'earned_on', 'note', 'status', 'created_by',
        'approved_by', 'approved_at', 'payroll_line_id', 'paid_at', 'void_reason', 'voided_by', 'voided_at'];

    protected $casts = ['amount' => 'decimal:2', 'earned_on' => 'date', 'approved_at' => 'datetime', 'paid_at' => 'datetime', 'voided_at' => 'datetime'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function line(): BelongsTo { return $this->belongsTo(PayrollLine::class, 'payroll_line_id'); }

    /** Is PAYE charged on commission? Off unless a super admin turns it on (owner, 2026-11-04). */
    public static function payeApplies(): bool
    {
        return filter_var(DB::table('settings')->where('key', 'payroll.commission_paye')->value('value') ?? '0', FILTER_VALIDATE_BOOLEAN);
    }
}

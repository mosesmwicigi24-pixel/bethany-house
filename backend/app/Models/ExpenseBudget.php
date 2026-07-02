<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Budget allocations for expense categories, per period.
 * Allows tracking budget vs. actual spend for each category/outlet/period.
 */
class ExpenseBudget extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'outlet_id',       // null = company-wide
        'period_type',     // monthly | quarterly | annual
        'period_year',
        'period_number',   // month (1-12), quarter (1-4), or 1 for annual
        'budgeted_amount',
        'currency_code',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'budgeted_amount' => 'decimal:2',
        'period_year'     => 'integer',
        'period_number'   => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Get actual spend for this budget period.
     */
    public function actualSpend(): float
    {
        $query = Expense::whereIn('status', ['approved', 'paid'])
            ->where('category_id', $this->category_id);

        if ($this->outlet_id) {
            $query->where('outlet_id', $this->outlet_id);
        }

        if ($this->period_type === 'monthly') {
            $query->whereYear('expense_date', $this->period_year)
                  ->whereMonth('expense_date', $this->period_number);
        } elseif ($this->period_type === 'quarterly') {
            $startMonth = ($this->period_number - 1) * 3 + 1;
            $endMonth   = $startMonth + 2;
            $query->whereYear('expense_date', $this->period_year)
                  ->whereMonth('expense_date', '>=', $startMonth)
                  ->whereMonth('expense_date', '<=', $endMonth);
        } else {
            $query->whereYear('expense_date', $this->period_year);
        }

        return (float) $query->sum('amount_kes');
    }

    public function variance(): float
    {
        return $this->budgeted_amount - $this->actualSpend();
    }

    public function utilizationPercent(): float
    {
        if (!$this->budgeted_amount) return 0;
        return round(($this->actualSpend() / $this->budgeted_amount) * 100, 1);
    }
}

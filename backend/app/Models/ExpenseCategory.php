<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',          // e.g. RENT, UTILITIES, SALARIES
        'description',
        'parent_id',
        'color',         // hex for UI display
        'icon',
        'requires_approval_above', // auto-trigger approval if amount exceeds this
        'budget_monthly',
        'budget_annual',
        'is_active',
        'is_tax_deductible',
        'gl_code',       // General Ledger code for accounting integrations
        'sort_order',
    ];

    protected $casts = [
        'requires_approval_above' => 'decimal:2',
        'budget_monthly'          => 'decimal:2',
        'budget_annual'           => 'decimal:2',
        'is_active'               => 'boolean',
        'is_tax_deductible'       => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function parent()
    {
        return $this->belongsTo(ExpenseCategory::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(ExpenseCategory::class, 'parent_id');
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class, 'category_id');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Current month spend for this category.
     */
    public function currentMonthSpend(): float
    {
        return $this->expenses()
            ->whereIn('status', ['approved', 'paid'])
            ->whereYear('expense_date', now()->year)
            ->whereMonth('expense_date', now()->month)
            ->sum('amount_kes');
    }

    /**
     * Budget utilization percentage for current month.
     */
    public function budgetUtilizationPercent(): ?float
    {
        if (!$this->budget_monthly || $this->budget_monthly == 0) return null;
        return round(($this->currentMonthSpend() / $this->budget_monthly) * 100, 1);
    }
}
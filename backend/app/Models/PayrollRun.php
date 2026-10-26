<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollRun extends Model
{
    public const MONEY = ['gross', 'taxable', 'paye', 'nssf_employee', 'nssf_employer', 'shif', 'housing_employee', 'housing_employer', 'other_deductions', 'net', 'employer_cost'];

    protected $fillable = ['reference', 'pay_period_id', 'pay_group_id', 'kind', 'status', 'people', 'gross', 'taxable', 'paye', 'nssf_employee', 'nssf_employer',
        'shif', 'housing_employee', 'housing_employer', 'other_deductions', 'net', 'employer_cost', 'rates_used', 'unpayable', 'expense_id', 'notes',
        'created_by', 'approved_by', 'approved_at', 'paid_at'];

    protected $casts = ['rates_used' => 'array', 'unpayable' => 'array', 'approved_at' => 'datetime', 'paid_at' => 'datetime',
        'gross' => 'decimal:2', 'taxable' => 'decimal:2', 'paye' => 'decimal:2', 'nssf_employee' => 'decimal:2', 'nssf_employer' => 'decimal:2', 'shif' => 'decimal:2',
        'housing_employee' => 'decimal:2', 'housing_employer' => 'decimal:2', 'other_deductions' => 'decimal:2', 'net' => 'decimal:2', 'employer_cost' => 'decimal:2'];

    public function period()     { return $this->belongsTo(PayPeriod::class, 'pay_period_id'); }
    public function group()      { return $this->belongsTo(PayGroup::class, 'pay_group_id'); }
    public function lines()      { return $this->hasMany(PayrollLine::class); }
    /**
     * The Salaries expense this run posted. Company-wide (no outlet), so it sits
     * outside an outlet-scoped viewer's expense list; the run itself is the
     * authority here (payroll.view), hence no viewer boundary on the relation.
     */
    public function expense()    { return $this->belongsTo(Expense::class)->withoutGlobalScope(\App\Models\Scopes\ViewerScope::class); }
    public function approvedBy() { return $this->belongsTo(User::class, 'approved_by'); }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollLine extends Model
{
    public const MONEY = ['basic', 'overtime_pay', 'rest_day_pay', 'holiday_pay', 'allowances', 'unpaid_deduction', 'gross', 'taxable',
        'paye', 'nssf_employee', 'nssf_employer', 'shif', 'housing_employee', 'housing_employer', 'other_deductions', 'net', 'employer_cost'];

    protected $guarded = ['id'];

    protected $casts = ['structure' => 'array', 'hours' => 'array', 'items' => 'array', 'paid_at' => 'datetime',
        'basic' => 'decimal:2', 'overtime_pay' => 'decimal:2', 'rest_day_pay' => 'decimal:2', 'holiday_pay' => 'decimal:2', 'allowances' => 'decimal:2',
        'unpaid_deduction' => 'decimal:2', 'gross' => 'decimal:2', 'taxable' => 'decimal:2', 'paye' => 'decimal:2', 'nssf_employee' => 'decimal:2',
        'nssf_employer' => 'decimal:2', 'shif' => 'decimal:2', 'housing_employee' => 'decimal:2', 'housing_employer' => 'decimal:2',
        'other_deductions' => 'decimal:2', 'net' => 'decimal:2', 'employer_cost' => 'decimal:2'];

    public function run()  { return $this->belongsTo(PayrollRun::class, 'payroll_run_id'); }
    public function user() { return $this->belongsTo(User::class); }
}

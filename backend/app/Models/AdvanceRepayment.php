<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdvanceRepayment extends Model
{
    protected $fillable = ['staff_advance_id', 'payroll_line_id', 'kind', 'amount', 'recorded_on', 'reference', 'recorded_by'];

    protected $casts = ['amount' => 'decimal:2', 'recorded_on' => 'date:Y-m-d'];

    public function advance() { return $this->belongsTo(StaffAdvance::class, 'staff_advance_id'); }
    public function line()    { return $this->belongsTo(PayrollLine::class, 'payroll_line_id'); }
}

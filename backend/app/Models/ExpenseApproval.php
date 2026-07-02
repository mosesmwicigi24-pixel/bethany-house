<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpenseApproval extends Model
{
    use HasFactory;

    protected $fillable = [
        'expense_id',
        'approver_id',
        'action',          // approved | rejected | requested_info
        'comments',
        'acted_at',
        'step',            // approval step number (for multi-level approval)
    ];

    protected $casts = [
        'acted_at' => 'datetime',
    ];

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
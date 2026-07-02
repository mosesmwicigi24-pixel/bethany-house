<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Individual line items within a multi-item expense (e.g. expense report / petty cash).
 */
class ExpenseLineItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'expense_id',
        'description',
        'category_id',
        'quantity',
        'unit_price',
        'amount',
        'tax_amount',
        'notes',
    ];

    protected $casts = [
        'quantity'   => 'decimal:2',
        'unit_price' => 'decimal:2',
        'amount'     => 'decimal:2',
        'tax_amount' => 'decimal:2',
    ];

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }
}

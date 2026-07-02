<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaxRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'country_code',
        'state_province',
        'rate',
        'tax_type',
        'is_active',
    ];

    protected $casts = [
        'rate' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function country()
    {
        return $this->belongsTo(Country::class, 'country_code', 'code');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function calculateTax($amount)
    {
        return $amount * $this->rate;
    }
}

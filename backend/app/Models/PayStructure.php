<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayStructure extends Model
{
    public const KINDS = ['monthly', 'daily', 'hourly'];

    protected $fillable = ['user_id', 'effective_from', 'kind', 'base_amount', 'currency_code', 'allowances', 'notes', 'created_by'];

    protected $casts = ['effective_from' => 'date:Y-m-d', 'base_amount' => 'decimal:2', 'allowances' => 'array'];

    public function user() { return $this->belongsTo(User::class); }
}

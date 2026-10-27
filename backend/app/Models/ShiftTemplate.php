<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftTemplate extends Model
{
    protected $fillable = ['outlet_id', 'name', 'starts_at', 'ends_at', 'label', 'created_by'];
}

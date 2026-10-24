<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublicHoliday extends Model
{
    protected $fillable = ['date', 'name', 'country', 'is_confirmed', 'notes', 'created_by'];

    protected $casts = [
        'date'         => 'date:Y-m-d',
        'is_confirmed' => 'boolean',
    ];
}

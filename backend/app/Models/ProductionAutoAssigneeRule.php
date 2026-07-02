<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionAutoAssigneeRule extends Model
{
    protected $table = 'production_auto_assignee_rules';

    protected $fillable = [
        'user_id',
        'role_in_order',
        'outlet_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }
}
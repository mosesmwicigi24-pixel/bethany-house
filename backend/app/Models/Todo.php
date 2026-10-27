<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Todo extends Model
{
    protected $fillable = ['user_id', 'created_by', 'outlet_id', 'title', 'details', 'due_at', 'status', 'done_at', 'done_note', 'reminded_at', 'overdue_told_at'];
    protected $casts = ['due_at' => 'datetime', 'done_at' => 'datetime', 'reminded_at' => 'datetime', 'overdue_told_at' => 'datetime'];
    public function user()    { return $this->belongsTo(User::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function outlet()  { return $this->belongsTo(Outlet::class); }
}

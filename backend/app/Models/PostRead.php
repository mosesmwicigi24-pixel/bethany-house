<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostRead extends Model
{
    public $timestamps = false;
    protected $fillable = ['post_id', 'user_id', 'read_at', 'acknowledged_at'];
    protected $casts = ['read_at' => 'datetime', 'acknowledged_at' => 'datetime'];
    public function user() { return $this->belongsTo(User::class); }
}

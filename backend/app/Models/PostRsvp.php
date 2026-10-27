<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostRsvp extends Model
{
    public const ANSWERS = ['yes', 'no', 'maybe'];
    protected $fillable = ['post_id', 'user_id', 'answer'];
    public function user() { return $this->belongsTo(User::class); }
}

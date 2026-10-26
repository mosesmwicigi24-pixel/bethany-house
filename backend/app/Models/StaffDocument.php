<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffDocument extends Model
{
    public const KINDS = ['national_id', 'kra_pin', 'nssf', 'shif', 'contract', 'certificate', 'passport_photo', 'medical', 'other'];

    protected $fillable = [
        'user_id', 'kind', 'title', 'disk', 'path', 'mime_type', 'size_bytes', 'original_name',
        'expires_at', 'uploaded_by', 'removed_at', 'removed_by',
    ];

    protected $casts = [
        'expires_at' => 'date:Y-m-d',
        'removed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeCurrent($q)
    {
        return $q->whereNull('removed_at');
    }
}

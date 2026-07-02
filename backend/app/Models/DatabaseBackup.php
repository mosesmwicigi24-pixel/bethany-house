<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DatabaseBackup extends Model
{
    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'type', 'status', 'filename', 'disk', 'path', 'size_bytes',
        'checksum_sha256', 'app_version', 'db_driver', 'triggered_by',
        'created_by', 'error_message', 'duration_seconds', 'expires_at',
    ];

    protected $casts = [
        'size_bytes'       => 'integer',
        'duration_seconds' => 'integer',
        'expires_at'       => 'datetime',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
    ];

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }
}

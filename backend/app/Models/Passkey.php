<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One WebAuthn credential a person's device holds (App\Services\Auth\Passkeys). */
class Passkey extends Model
{
    protected $fillable = ['user_id', 'name', 'credential_id', 'public_key', 'transports', 'sign_count', 'aaguid', 'last_used_at'];

    protected $casts = [
        'transports'   => 'array',
        'sign_count'   => 'integer',
        'last_used_at' => 'datetime',
    ];

    /** The public key material never leaves the server. */
    protected $hidden = ['public_key', 'credential_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array{id:int, name:string, transports:array, created_at:?string, last_used_at:?string} */
    public function present(): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'transports'   => $this->transports ?? [],
            'created_at'   => $this->created_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
        ];
    }
}

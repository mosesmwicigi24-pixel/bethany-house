<?php

namespace App\Models;

use App\Enums\IdentityProvider;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single channel identity a customer is reachable through — e.g. their
 * WhatsApp wa_id, Instagram IGSID, or Messenger PSID. See the
 * customer_identities migration for the shape and the Neema epic doc
 * (docs/NEEMA_MULTICHANNEL_IDENTITY.md) for the why.
 */
class CustomerIdentity extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'provider',
        'provider_uid',
        'username',
        'display_name',
        'phone_e164',
        'profile',
        'verified_at',
        'last_interaction_at',
    ];

    protected $casts = [
        'provider'            => IdentityProvider::class,
        'profile'             => 'array',
        'verified_at'         => 'datetime',
        'last_interaction_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Was this identity minted from a platform-authenticated source?
     */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}

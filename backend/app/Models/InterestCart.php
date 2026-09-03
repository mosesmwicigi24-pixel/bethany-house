<?php

namespace App\Models;

use App\Support\Phone;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of the interest ledger — a cart as an expression of interest,
 * keyed to the cross-channel token ("BH-XXXX") that Neema's WhatsApp
 * handoff carries. See docs/HUB_STOREFRONT_ENDPOINTS.md and the storefront
 * repo's HUB_CONTRACT.md §7.
 *
 * Pipeline truth only: never revenue, cash, or receivables. Rows are never
 * hard-deleted — "abandoned" is a status, not a delete.
 */
class InterestCart extends Model
{
    protected $fillable = [
        'token', 'channel', 'last_channel', 'status',
        'visitor_id', 'session_id',
        'phone', 'phone_canonical', 'name', 'church',
        'messenger_psid', 'instagram_psid',
        'items', 'subtotal', 'currency',
        'order_ref', 'source_path', 'client_request_id',
        'converted_at',
    ];

    protected $casts = [
        'items'        => 'array',
        'subtotal'     => 'decimal:2',
        'converted_at' => 'datetime',
    ];

    public const CHANNELS = ['web', 'whatsapp', 'messenger', 'instagram', 'facebook'];

    public const STATUS_ACTIVE   = 'active_cart';
    public const STATUS_CHECKOUT = 'checkout_started';
    public const OUTCOMES        = ['online_order', 'whatsapp_order', 'abandoned'];
    public const CONVERTED       = ['online_order', 'whatsapp_order'];

    /**
     * The status ladder (§7a: "never drop a status backwards"). Converted
     * outcomes outrank everything; abandoned outranks the live states but
     * must never overwrite a conversion — a sale already closed stays closed.
     */
    private const STATUS_RANK = [
        'active_cart'      => 0,
        'checkout_started' => 1,
        'abandoned'        => 2,
        'online_order'     => 3,
        'whatsapp_order'   => 3,
    ];

    /** Would moving to $incoming regress this row's status? */
    public function statusWouldRegress(string $incoming): bool
    {
        $current = self::STATUS_RANK[$this->status] ?? 0;
        $next    = self::STATUS_RANK[$incoming] ?? 0;

        return $next < $current
            || (in_array($this->status, self::CONVERTED, true) && $incoming === 'abandoned');
    }

    /** Stamp phone + its canonical join key together, never one without the other. */
    public function setPhoneAttribute(?string $value): void
    {
        $this->attributes['phone']           = $value;
        $this->attributes['phone_canonical'] = Phone::canonical($value);
    }
}

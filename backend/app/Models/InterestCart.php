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
     * Would moving to $incoming regress this row's status?
     *
     * Three rules, in order of what the statuses actually mean:
     *  - CONVERTED IS TERMINAL: a sale that closed stays closed — neither a
     *    stale browser tab syncing active_cart nor an abandonment sweep can
     *    undo it (§7a/§7c).
     *  - ABANDONED IS DORMANT, NOT DEAD: the token lives in the customer's
     *    browser until an order rotates it, so "customer comes back after
     *    three weeks" is the NORMAL path — any new touch revives the cart.
     *  - AMONG LIVE STATES, FORWARD ONLY: checkout_started never drops back
     *    to active_cart ("never drop a status backwards").
     */
    public function statusWouldRegress(string $incoming): bool
    {
        if (in_array($this->status, self::CONVERTED, true)) {
            return !in_array($incoming, self::CONVERTED, true);
        }
        if ($this->status === 'abandoned') {
            return false;
        }

        return $this->status === self::STATUS_CHECKOUT && $incoming === self::STATUS_ACTIVE;
    }

    /** Converted carts are history — the record of what was bought. */
    public function isConverted(): bool
    {
        return in_array($this->status, self::CONVERTED, true);
    }

    /** Stamp phone + its canonical join key together, never one without the other. */
    public function setPhoneAttribute(?string $value): void
    {
        $this->attributes['phone']           = $value;
        $this->attributes['phone_canonical'] = Phone::canonical($value);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A storefront lead captured by Neema. See docs/HUB_CONTRACT.md §1.
 */
class Lead extends Model
{
    protected $fillable = [
        'client_request_id', 'intent', 'readiness',
        'name', 'phone', 'email', 'church',
        'country_code', 'city',
        'products', 'quantity', 'message', 'source_path',
        'status', 'assigned_to',
    ];

    protected $casts = [
        'products' => 'array',
    ];

    public const INTENTS = ['quote', 'shipping', 'product_inquiry', 'measurement', 'order_support', 'other'];

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}

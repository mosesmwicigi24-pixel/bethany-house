<?php

namespace App\Models;

use App\Models\Concerns\Restricted;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    /* ── Revenue recognition ──────────────────────────────────────────────
     *
     * An order is income only once a HUMAN has accepted it. Customers add to a
     * cart on the storefront or on WhatsApp and wander off; those carts land
     * here as `pending` and are a sales *pipeline*, not revenue. Counting them
     * overstated the last-30-day sales report by KES 1,136,060 — 33% of the
     * reported figure, and 82% of the Online channel — because the only filter
     * anywhere was `status NOT IN (voided, cancelled)`.
     *
     * The three stages a confirmed order moves through, and the labels the UI
     * shows for each:
     *
     *   confirmed                        → "Confirmed"  (amber)
     *   processing | shipped | delivered → "Processed"  (pink)
     *   completed                        → "Completed"  (green)
     *
     * All three are recognised income. POS is unaffected in practice — a till
     * sale is confirmed at the moment of payment — so this restates the online
     * and WhatsApp channels only.
     */

    /** Income. A human accepted the order; the sale is real. */
    public const RECOGNISED_STATUSES = ['confirmed', 'processing', 'shipped', 'delivered', 'completed'];

    /** Pipeline. An unconfirmed cart — reportable, but NEVER as income. */
    public const PIPELINE_STATUSES = ['pending'];

    /**
     * The four staff queues an order can live in — HOW it becomes money.
     * Orthogonal to source_channel (WHERE the customer came from) and to
     * order_type (untouched legacy field the public storefront lookup returns).
     *
     *   till    walk-in, paid at the counter
     *   web     self-service storefront checkout
     *   chat    sold in a conversation (WhatsApp / Messenger / Instagram)
     *   quoted  quotation → invoice → payable order (the sales desk)
     */
    public const SALES_BUCKETS = ['till', 'web', 'chat', 'quoted'];

    /** Old channel names still arrive from callers; they map, never 404. */
    public const LEGACY_CHANNEL_MAP = ['pos' => 'till', 'online' => 'web', 'whatsapp' => 'chat'];

    /** Neither: the order is dead and belongs in no sales figure. */
    public const DEAD_STATUSES = ['cancelled', 'voided', 'refunded'];

    /**
     * Payment states that mean real money arrived.
     *
     * A customer who pays a payment link before any staff member opens the
     * order leaves it `pending` while the cash is already banked. Money in the
     * account is not a browsing cart, so payment is a second, independent route
     * to recognition — whichever happens first, confirmation or payment.
     */
    public const SETTLED_PAYMENT_STATUSES = ['paid', 'partial', 'deposit'];

    /** status → the stage label the three channel reports group by. */
    public const STAGE_OF_STATUS = [
        'confirmed'  => 'confirmed',
        'processing' => 'processed',
        'shipped'    => 'processed',
        'delivered'  => 'processed',
        'completed'  => 'completed',
    ];

    /**
     * Recognised income only. The base for every sales figure.
     *
     * Recognised = a human confirmed it, OR money actually arrived — and the
     * order is not dead. The dead check is not redundant: a cancelled order can
     * still carry a settled payment (that is what a refund unwinds), and
     * without it the payment arm would drag cancelled orders back into revenue.
     */
    public function scopeRecognised($query)
    {
        return $query
            ->whereNotIn('orders.status', self::DEAD_STATUSES)
            ->where(fn ($q) => $q
                ->whereIn('orders.status', self::RECOGNISED_STATUSES)
                ->orWhereIn('orders.payment_status', self::SETTLED_PAYMENT_STATUSES));
    }

    /**
     * Unconfirmed carts — the pipeline report, never the sales report.
     *
     * The exact complement of recognised() within the live order book: pending
     * AND no money received. A pending order that has been paid is income, not
     * pipeline, so it must not appear in both.
     */
    public function scopePipeline($query)
    {
        return $query
            ->whereIn('orders.status', self::PIPELINE_STATUSES)
            ->whereNotIn('orders.payment_status', self::SETTLED_PAYMENT_STATUSES);
    }

    use HasFactory;
    /**
     * Bounds every order query to what the caller may see — list, detail,
     * export, search, PDF, notification payloads and anything written later.
     * Inert until a role's data_scope is narrowed from 'all'.
     */
    use Restricted;

    /**
     * Every order gets its durable customer-facing token AT CREATION, on the
     * model — not in a controller. Orders are raised from at least five places
     * (POS, storefront checkout, admin, quotation conversion, Neema's
     * pending-order push); minting per-controller would give four of them a
     * token and leave the fifth with a customer who cannot see their receipt.
     */
    protected static function booted(): void
    {
        static::creating(function (self $order) {
            if (empty($order->public_token)) {
                $order->public_token           = bin2hex(random_bytes(24));
                $order->public_token_issued_at = now();
            }
        });
    }

    /** The capability that governs reading an order. */
    public static function viewPermission(): string
    {
        return 'orders.view';
    }

    /**
     * created_by is the STAFF member who raised the order. user_id is the
     * customer's linked account — scoping on it would show a clerk the orders
     * placed against their own customer record, which is not what "own sales"
     * means.
     */
    public function ownerColumn(): string
    {
        return 'created_by';
    }

    protected $fillable = [
        'order_number',
        // Idempotency key for POS sale creation — without this in $fillable, mass
        // assignment silently drops it and every submit looks like a new sale.
        'client_request_id',
        'user_id',
        'outlet_id',
        'order_type',
        'sales_bucket',
        'source_channel',
        'status',
        'currency_code',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'prices_include_tax',
        'shipping_amount',
        'shipping_fee_overridden',
        'shipping_fee_note',
        'total_amount',
        'deposit_amount',
        'balance_due_date',
        'customer_email',
        'customer_phone',
        'customer_first_name',
        'customer_last_name',
        'customer_country_code',
        'billing_address_line1',
        'billing_address_line2',
        'billing_city',
        'billing_state',
        'billing_postal_code',
        'billing_country_code',
        'shipping_address_line1',
        'shipping_address_line2',
        'shipping_city',
        'shipping_state',
        'shipping_postal_code',
        'shipping_country_code',
        'shipping_method',
        'delivery_type',
        'pickup_outlet_id',
        'payment_method',
        'payment_status',
        'notes',
        'customer_notes',
        'ip_address',
        'user_agent',
        'completed_at',
        'cancelled_at',
        'dispatched_at',
        'dispatched_by',
        'stock_reserved_at',
        'stock_committed_at',
        'stock_unwound_at',
        'payment_token',
        'payment_token_expires_at',
        'is_international',
        'created_by',
    ];

    /**
     * public_token IS the authorisation to read an order — anyone holding it
     * sees the customer's name, phone, items and total. It must therefore
     * never ride along in an admin list, a detail payload, a search result or
     * a notification body, where it would be logged, forwarded and cached.
     * The two places that legitimately hand it out (the pending-order response
     * and PublicOrderController) add it explicitly.
     *
     * payment_token is deliberately NOT hidden here: nothing consumes it from a
     * serialized order today, but hiding it is a separate change with its own
     * blast radius, and it grants only a 72-hour pay session to a staff member
     * who could mint one from the API anyway.
     */
    protected $hidden = [
        'public_token',
    ];

    protected $casts = [
        'subtotal'               => 'decimal:2',
        'discount_amount'        => 'decimal:2',
        'tax_amount'             => 'decimal:2',
        'shipping_amount'        => 'decimal:2',
        'deposit_amount'         => 'decimal:2',
        'total_amount'           => 'decimal:2',
        'prices_include_tax'     => 'boolean',
        'shipping_fee_overridden'=> 'boolean',
        'balance_due_date'       => 'date',
        'completed_at'           => 'datetime',
        'cancelled_at'           => 'datetime',
        'dispatched_at'          => 'datetime',
        'stock_reserved_at'      => 'datetime',
        'stock_committed_at'     => 'datetime',
        'stock_unwound_at'       => 'datetime',
        'payment_token_expires_at' => 'datetime',
    ];

    protected $appends = ['customer_name'];

    /**
     * Computed accessor — returns a display name for the customer regardless
     * of whether a User account is linked or just denormalised name columns
     * are stored on the order (e.g. POS walk-in customers).
     */
    public function getCustomerNameAttribute(): ?string
    {
        if ($this->user) {
            return trim($this->user->first_name . ' ' . $this->user->last_name) ?: null;
        }
        return trim(($this->customer_first_name ?? '') . ' ' . ($this->customer_last_name ?? '')) ?: null;
    }

    /**
     * Relationships
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The staff member who raised this order — the person who served the
     * customer. Distinct from user(), which is the customer's own account.
     *
     * This is the same column the viewer scope owns an order by, so "the
     * orders you can see" and "the orders that say your name" are guaranteed
     * to be the same set.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The customer record this order is attached to. POS can set customer_id
     * while leaving the snapshot name columns blank, so this is the last
     * resort when resolving who an order (or its production job) is for.
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function pickupOutlet()
    {
        return $this->belongsTo(Outlet::class, 'pickup_outlet_id');
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistory()
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /** The INVOICE document this order bills (if it came from a quotation). */
    public function invoiceDocument()
    {
        return $this->morphOne(SalesDocument::class, 'documentable')
            ->where('type', SalesDocument::INVOICE);
    }

    public function shipments()
    {
        return $this->hasMany(OrderShipment::class);
    }

    public function returns()
    {
        return $this->hasMany(OrderReturn::class);
    }

    /**
     * Scopes
     */
    public function scopeOnline($query)
    {
        return $query->where('order_type', 'online');
    }

    public function scopePos($query)
    {
        return $query->where('order_type', 'pos');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to a SALES CHANNEL — pos / online / whatsapp.
     *
     * Channel is DERIVED, not a column: WhatsApp is either an order taken at a
     * WhatsApp-channel outlet or one tagged order_type='whatsapp' (sold through
     * WhatsApp but fulfilled at a physical store — channel and outlet are
     * orthogonal), and POS is everything else of type 'pos'.
     *
     * This lives on the model because both OrderController (the three Sales
     * pages) and ReportController (the sales report) must split orders the SAME
     * way. Two copies of this rule would drift, and the first symptom would be
     * a report whose channel totals disagree with the order list it is supposed
     * to summarise — the kind of discrepancy that destroys trust in a report.
     */
    public function scopeSalesChannel($query, ?string $channel)
    {
        // Old names keep working: three list pages, the sales ledger and any
        // bookmarked export URL predate the buckets.
        $bucket = self::LEGACY_CHANNEL_MAP[$channel] ?? $channel;

        if (! in_array($bucket, self::SALES_BUCKETS, true)) {
            return $query;
        }

        // Bucket-first, with a legacy derivation for rows whose bucket is NULL
        // (fixtures, or a writer added later that forgot — the guard test
        // exists, but a forgotten row must degrade to the old behaviour, not
        // vanish from every list).
        $whatsappOutletIds = \App\Models\Outlet::where('sales_channel', 'whatsapp')->pluck('id');

        // A WhatsApp-channel outlet belongs to chat, whatever else the row looks
        // like — the precedence the backfill applied (its chat statement overwrote
        // both the pos and the online-split rows). Every non-chat bucket must
        // therefore EXCLUDE WhatsApp outlets, or an order recorded against one
        // matches two buckets in the legacy fallback and double-counts in
        // weekly/monthly totals. whereNotIn alone would DROP orders with a NULL
        // outlet_id: in SQL `NULL NOT IN (1)` evaluates to NULL, not true, so a
        // POS order without an outlet would silently vanish. The null branch is
        // explicit, and this one predicate is shared by till, web and quoted.
        $notWhatsappOutlet = fn ($qq) => $qq->whereNull('orders.outlet_id')
                                            ->orWhereNotIn('orders.outlet_id', $whatsappOutletIds);

        $legacy = match ($bucket) {
            'web'    => fn ($q) => $q->where('orders.order_type', 'online')
                                     ->whereNull('orders.created_by')
                                     ->where($notWhatsappOutlet),
            'quoted' => fn ($q) => $q->where('orders.order_type', 'online')
                                     ->whereNotNull('orders.created_by')
                                     ->where($notWhatsappOutlet),
            'chat'   => fn ($q) => $q->where(function ($qq) use ($whatsappOutletIds) {
                $qq->whereIn('orders.outlet_id', $whatsappOutletIds)
                   ->orWhere('orders.order_type', 'whatsapp');
            }),
            'till'   => fn ($q) => $q->where('orders.order_type', 'pos')
                                     ->where($notWhatsappOutlet),
        };

        return $query->where(function ($q) use ($bucket, $legacy) {
            $q->where('orders.sales_bucket', $bucket)
              ->orWhere(fn ($qq) => $qq->whereNull('orders.sales_bucket')->where($legacy));
        });
    }

    public function scopePaid($query)
    {
        return $query->where('payment_status', 'paid');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Helper methods
     */
    public function isPaid()
    {
        return $this->payment_status === 'paid';
    }

    public function isCompleted()
    {
        return $this->status === 'completed';
    }

    public function isCancelled()
    {
        return $this->status === 'cancelled';
    }

    public function canBeCancelled()
    {
        return in_array($this->status, ['pending', 'processing']) && !$this->cancelled_at;
    }
    /**
     * Is this an international order (outside Kenya)?
     */
    public function isInternational(): bool
    {
        return !empty($this->customer_country_code)
            && strtoupper($this->customer_country_code) !== 'KE';
    }

    /**
     * Resolve the correct currency for an order based on destination country.
     * Kenya (KE) → KES, everywhere else → USD.
     */
    public static function resolveCurrency(string $countryCode): string
    {
        return strtoupper($countryCode) === 'KE' ? 'KES' : 'USD';
    }

    /**
     * Net amount collected across all settled payment records — gross paid minus
     * anything refunded back. Voided payments (status != 'paid') don't count at
     * all; refunded ones count only for the un-refunded remainder. This makes
     * void/refund reconcile correctly (audit MON-1): a fully-refunded order nets
     * to 0 and syncPaymentStatus() moves it back to 'pending'.
     */
    public function totalPaid(): float
    {
        return (float) $this->payments()
            ->where('status', 'paid')
            ->selectRaw('COALESCE(SUM(amount - refund_amount), 0) AS net')
            ->value('net');
    }

    /**
     * Amount still outstanding.
     */
    public function outstandingBalance(): float
    {
        return max(0, (float) $this->total_amount - $this->totalPaid());
    }

    /**
     * Re-evaluate and persist payment_status based on current payments.
     * Call after every payment is recorded or voided.
     */
    public function syncPaymentStatus(): void
    {
        $paid      = $this->totalPaid();
        $total     = (float) $this->total_amount;
        $isDeposit = !is_null($this->deposit_amount) && $paid > 0 && $paid < $total;

        if ($paid <= 0) {
            $status = 'pending';
        } elseif ($isDeposit) {
            $status = 'deposit';
        } elseif ($paid < $total) {
            $status = 'partial';
        } else {
            $status = 'paid';
        }

        $this->update(['payment_status' => $status]);

        if ($status === 'paid') {
            $this->commitStockOnceFullyPaid();
        }
    }

    /**
     * Fully paid means the goods leave the shelf. This lives HERE, on the one
     * method every payment door already calls, because putting it at each call
     * site meant a door could forget — and one did.
     *
     * PosController and ReceiptService committed; PublicPaymentController never
     * did. So every order a CUSTOMER paid for themselves — the flow the durable
     * order link was built to make primary — took the money and left the shelf
     * count untouched: 82 orders, KES 1.16m, still happening. commitForOrder is
     * idempotent, so the doors that already commit are unaffected.
     *
     * NEVER throws outward. By the time this runs the money has arrived, and a
     * stock-bookkeeping problem must not undo a customer's payment. A stale
     * hold is logged loudly for staff instead — the till refuses that order
     * BEFORE taking payment (PosController::recordPosPay), which is the right
     * place to stop it.
     */
    private function commitStockOnceFullyPaid(): void
    {
        if (in_array($this->status, self::DEAD_STATUSES, true)) {
            return;
        }

        try {
            \App\Services\PosInventoryService::commitForOrder($this, $this->created_by);
            \App\Services\ProductSerialService::syncSoldForOrder($this);
        } catch (\App\Exceptions\StaleStockHoldException $e) {
            \Illuminate\Support\Facades\Log::error('stock not deducted on payment — stale hold', [
                'order_id'     => $this->id,
                'order_number' => $this->order_number,
                'message'      => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('stock commit failed after payment', [
                'order_id'     => $this->id,
                'order_number' => $this->order_number,
                'message'      => $e->getMessage(),
            ]);
        }
    }

}
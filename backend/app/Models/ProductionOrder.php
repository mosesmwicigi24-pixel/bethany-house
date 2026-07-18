<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductionOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number',
        'is_customer_order',
        'customer_id',
        'product_id',
        'product_variant_id',
        'customer_order_id',
        'order_item_id',
        'quantity',
        'status',
        'priority',
        'due_date',
        'fitting_date',
        'collection_date',
        'estimated_completion_date',
        'started_at',
        'confirmed_at',
        'confirmed_by',
        'completed_at',
        'outlet_id',
        'target_outlet_id',
        'specifications',
        'measurements',
        'customer_preferences',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'quantity'                  => 'integer',
        'due_date'                  => 'date',
        'fitting_date'              => 'date',
        'collection_date'           => 'date',
        'estimated_completion_date' => 'date',
        'started_at'                => 'datetime',
        'confirmed_at'              => 'datetime',
        'completed_at'              => 'datetime',
        'specifications'            => 'array',
        'measurements'              => 'array',
        'customer_preferences'      => 'array',
        'is_customer_order'         => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            if (empty($order->order_number)) {
                $order->order_number = 'PRD-' . date('Ymd') . '-'
                    . str_pad(static::whereDate('created_at', today())->count() + 1, 4, '0', STR_PAD_LEFT);
            }
            // New orders start as draft - they enter the queue only after payment/confirmation
            if (empty($order->status)) {
                $order->status = 'draft';
            }
        });
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function customerOrder()
    {
        return $this->belongsTo(Order::class, 'customer_order_id');
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function targetOutlet()
    {
        return $this->belongsTo(Outlet::class, 'target_outlet_id');
    }

    public function tasks()
    {
        return $this->hasMany(ProductionTask::class);
    }

    /**
     * Seed this order's tasks from its product's stage template.
     *
     * A keyholder does not pass through Embroidery; a chasuble does. The product
     * decides which stages its orders carry (products.production_stage_ids, set
     * on the product form beside Measurements). A product with no template —
     * null or empty — gets every active stage, which is what all orders got
     * before templates existed, so untouched products behave identically.
     *
     * `sequence` is stamped 1..N here, from the stage catalogue's sort order at
     * THIS moment. Gating reads only the snapshot: re-ordering stages in Setup
     * tomorrow must not re-gate a floor full of half-finished orders.
     *
     * Idempotent (firstOrCreate) — both the create path and the confirm path
     * call it, whichever runs second finds the rows already there. This method
     * is the ONLY task seeder; the two controller sites both delegate here.
     */
    public function seedTasks(): void
    {
        $templateIds = $this->product?->production_stage_ids ?? [];

        $stages = ProductionStage::active()
            ->when(
                !empty($templateIds),
                fn ($q) => $q->whereIn('id', $templateIds),
            )
            ->ordered()
            ->get();

        foreach ($stages->values() as $i => $stage) {
            ProductionTask::firstOrCreate(
                [
                    'production_order_id' => $this->id,
                    'production_stage_id' => $stage->id,
                ],
                [
                    'status'   => 'pending',
                    'sequence' => $i + 1,
                ],
            );
        }
    }

    public function materialAllocations()
    {
        return $this->hasMany(MaterialAllocation::class);
    }

    public function assignees()
    {
        return $this->hasMany(ProductionOrderAssignee::class);
    }

    /**
     * Scope production orders to what this user is allowed to SEE.
     *
     * Coordinators — anyone who manages assignees, raises or confirms orders,
     * or holds an admin role — see the whole floor. A worker sees only orders
     * they are actually part of: a task assigned to them, or membership on the
     * order's assignee list. This is the server-side gate; hiding menu items is
     * presentation, not security.
     */
    public function scopeVisibleTo($query, \App\Models\User $user)
    {
        if ($user->hasAnyRole(['admin', 'super_admin'])
            || $user->can('production.manage_assignees')
            || $user->can('production.raise_order')
            || $user->can('production.confirm_order')) {
            return $query;
        }

        return $query->where(function ($q) use ($user) {
            $q->whereHas('tasks', fn ($t) => $t->where('assigned_to', $user->id))
              ->orWhereHas('assignees', fn ($a) => $a->where('user_id', $user->id));
        });
    }

    public function approvals()
    {
        return $this->hasMany(ProductionOrderApproval::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', ['completed', 'cancelled', 'draft']);
    }

    public function scopeHighPriority($query)
    {
        return $query->where('priority', 'high');
    }

    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', now())->whereNull('completed_at');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isOverdue(): bool
    {
        return $this->due_date && $this->due_date->isPast() && !$this->completed_at;
    }

    public function getCompletionPercentage(): int
    {
        // Piece-weighted: each stage contributes the share of pieces that have
        // passed it. A 50-piece order with 10 through all 8 stages reads 20%,
        // not 0% — the old task-count version only moved when a whole stage
        // finished. For quantity-1 orders the two formulas agree.
        $tasks = $this->tasks()->get(['status', 'quantity_done']);
        if ($tasks->isEmpty()) {
            return 0;
        }
        $qty = max(1, (int) $this->quantity);
        $sum = $tasks->sum(fn ($t) => $t->effectivePassed($qty));

        return (int) round($sum / ($qty * $tasks->count()) * 100);
    }
}
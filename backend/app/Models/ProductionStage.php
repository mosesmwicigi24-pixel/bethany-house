<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductionStage extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'color',
        'sort_order',
        'is_active',
        'default_assignee_mode',
        'default_assignee_id',
    ];

    /** Who does this stage by default (owner, 2026-11-26). */
    public const ASSIGN_NONE    = 'none';            // handed out on each order
    public const ASSIGN_CREATOR = 'order_creator';   // whoever is serving the customer
    public const ASSIGN_PERSON  = 'person';          // default_assignee_id
    public const ASSIGN_MODES   = [self::ASSIGN_NONE, self::ASSIGN_CREATOR, self::ASSIGN_PERSON];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function defaultAssignee()
    {
        return $this->belongsTo(User::class, 'default_assignee_id');
    }

    /**
     * The user id this stage goes to on a new production order, or null when
     * it is handed out by hand. Only someone who can still work: an inactive
     * or removed account is never given a task.
     *
     * "Whoever is serving the customer" is who raised the customer's order;
     * for a job with no customer order (stock production), who raised the job.
     */
    public function defaultAssigneeFor(ProductionOrder $order): ?int
    {
        $userId = match ($this->default_assignee_mode) {
            self::ASSIGN_PERSON  => $this->default_assignee_id,
            self::ASSIGN_CREATOR => $order->customerOrder?->created_by ?: $order->created_by,
            default              => null,
        };
        if (!$userId) {
            return null;
        }

        $user = User::find($userId);

        return $user && $user->isActive() && $user->isStaff() ? (int) $user->id : null;
    }

    public function tasks()
    {
        return $this->hasMany(ProductionTask::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
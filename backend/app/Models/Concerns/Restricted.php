<?php

namespace App\Models\Concerns;

use App\Models\Scopes\ViewerScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Marks a model whose rows are bounded by the caller's data scope.
 *
 * A model using this trait must say three things: which capability governs
 * reading it, which column records who created a row, and which column records
 * the outlet a row belongs to.
 *
 * The escape hatch is deliberately explicit and greppable. Reports, queue jobs
 * and reconciliation code that genuinely must see everything call
 * `Model::withoutViewerScope()`, and every such call is a line a reviewer can
 * find — unlike the current arrangement, where "sees everything" is the silent
 * default and the exceptions are the scoped queries.
 */
trait Restricted
{
    protected static function bootRestricted(): void
    {
        static::addGlobalScope(new ViewerScope());
    }

    /** The capability that governs reading this model, e.g. 'orders.view'. */
    abstract public static function viewPermission(): string;

    /** Column holding the id of the user who created the row. */
    public function ownerColumn(): string
    {
        return 'created_by';
    }

    /** Column holding the outlet the row belongs to. */
    public function outletColumn(): string
    {
        return 'outlet_id';
    }

    /**
     * Query without the viewer boundary. For code that runs outside a user's
     * view of the world — reports, jobs, reconciliation — and never as a way
     * around a refusal.
     */
    public static function withoutViewerScope(): Builder
    {
        return static::withoutGlobalScope(ViewerScope::class);
    }
}

<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\User;

/**
 * ONE definition of "which outlets may this caller touch".
 *
 * Extracted from ExpenseController (#266) once a second controller —
 * DocumentPdfController, which renders the very same expense as a PDF —
 * needed the identical decision. Copying it would have meant two places to
 * keep in step, which is how the PDF route came to be missing the check in
 * the first place.
 *
 * The rule these encode, and the traps in it:
 *
 *   - Users have NO `outlet_id` column. Assignment is the many-to-many
 *     `outlet_user` pivot (User::outlets()). The original scoping read
 *     `$user->outlet_id`, which Laravel compiled to `outlet_id IS NULL` —
 *     it scoped nothing at all (#20).
 *   - An EMPTY assignment array means "nothing", never "everything". A
 *     manager who has not been attached to an outlet must not fall open to
 *     the whole group.
 *   - A NULL outlet_id on a record (head office) is OUT of scope for a
 *     scoped manager, matching what their list queries have always returned.
 *
 * The 403 message is the existing convention, shared with
 * PosController::authoriseOutletAccess and MetricEngine::for.
 */
trait ScopesToAssignedOutlets
{
    /**
     * Outlet ids this user may see and act on.
     *
     * null  → unrestricted (admins, super admins, and any role that is not
     *         outlet-scoped, e.g. finance).
     * array → exactly the outlets assigned via the outlet_user pivot. An
     *         EMPTY array is meaningful: a manager with no assignment sees
     *         nothing, rather than falling open to everything.
     *
     * @return int[]|null
     */
    private function assignedOutletIdsOrNull(User $user): ?array
    {
        if (!$user->hasRole('outlet_manager') || $user->hasAnyRole(['admin', 'super_admin'])) {
            return null;
        }

        return $user->outlets()->pluck('outlets.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * 403 unless $outletId is inside the caller's scope. A null outlet — a
     * head-office record — is out of scope for a scoped manager, exactly as
     * it is excluded from their list query.
     *
     * @param int[]|null $scope
     */
    private function authoriseOutletScope(?array $scope, ?int $outletId): void
    {
        if ($scope === null) {
            return;
        }

        if ($outletId === null || !in_array($outletId, $scope, true)) {
            abort(403, 'You do not have access to this outlet.');
        }
    }

    /**
     * Convenience: authorise a record's `outlet_id` against the caller's
     * scope in one step. Handles the NULL-outlet case for you.
     */
    private function authoriseOutletScopeFor(User $user, mixed $outletId): void
    {
        $this->authoriseOutletScope(
            $this->assignedOutletIdsOrNull($user),
            $outletId === null ? null : (int) $outletId,
        );
    }
}

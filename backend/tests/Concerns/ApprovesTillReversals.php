<?php

namespace Tests\Concerns;

use App\Models\ApprovalRequest;
use App\Models\ApprovalSignature;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use Illuminate\Http\Exceptions\HttpResponseException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Since Phase 4B part 2 a till void or return only ASKS; the sale is voided or
 * refunded when the approval engine's last band signs. Tests that pin what a
 * void or refund DOES (which drawer, which ledger row) ask, then sign every
 * band here with a fresh outside approver per band, and assert as before.
 */
trait ApprovesTillReversals
{
    protected function approveTillReversal(int $approvalId): ApprovalRequest
    {
        $engine = app(ApprovalEngine::class);

        for ($i = 0; $i < 5; $i++) {
            $request = ApprovalRequest::findOrFail($approvalId);
            if (!$request->isPending()) {
                return $request;
            }

            [$band] = $engine->effectiveBand($request);
            $approver = User::factory()->create(['status' => 'active']);
            if ($band['permission'] === ApprovalEngine::SUPER_PERMISSION) {
                $approver->assignRole(Role::findOrCreate('super_admin', 'sanctum'));
            } else {
                $approver->givePermissionTo(Permission::findOrCreate($band['permission'], 'sanctum'));
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            try {
                $engine->sign($request, $approver->fresh(), ApprovalSignature::APPROVED);
            } catch (HttpResponseException $e) {
                $this->fail('Signing the till reversal was refused: ' . $e->getResponse()->getContent());
            }
        }

        return ApprovalRequest::findOrFail($approvalId);
    }
}

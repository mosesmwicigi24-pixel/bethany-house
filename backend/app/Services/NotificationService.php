<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\EodReportRepliedNotification;
use App\Notifications\OrderPlacedNotification;
use App\Notifications\OrderStatusChangedNotification;
use App\Notifications\PaymentReceivedNotification;
use App\Notifications\PaymentApprovalRequiredNotification;
use App\Notifications\PaymentApprovalDecisionNotification;
use App\Notifications\LowStockAlertNotification;
use App\Notifications\ProductionAssignedNotification;
use App\Notifications\ProductionStageCompletedNotification;
use App\Notifications\ProductionOverdueNotification;
use App\Notifications\ProductionCancelledNotification;
use App\Notifications\ShipmentStatusChangedNotification;
use App\Notifications\UserWelcomeNotification;
use App\Notifications\InAppNotification;
use App\Services\WebPushService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use App\Events\NotificationPushed;

/**
 * NotificationService
 *
 * Central dispatch hub for all in-app notifications.
 * All methods are static for easy calling from controllers.
 *
 * Notifications are stored via the Laravel 'database' channel and
 * surfaced in the Topbar bell + /notifications page.
 *
 * Pattern: NotificationService::<eventName>(...identifiers...)
 */
class NotificationService
{
    // ── Internal send helper ──────────────────────────────────────────────────

    /**
     * Send a notification to one or more users, swallowing non-critical errors.
     * Phase 2: also broadcasts via Reverb (bell badge) + Web Push (device).
     *
     * @param  User|Collection|array  $recipients
     * @param  \Illuminate\Notifications\Notification  $notification
     */
    private static function send($recipients, $notification): void
    {
        $targets = $recipients instanceof User
            ? collect([$recipients])
            : ($recipients instanceof Collection ? $recipients : collect($recipients));

        if ($targets->isEmpty()) return;

        // Delivery makes blocking EXTERNAL push calls (Expo, Web Push) + a
        // synchronous broadcast per recipient. In an HTTP request that latency
        // was added to the caller's wait — most painfully a POS "pay" — so we
        // run it AFTER the response is flushed. Money/stock are already committed
        // before this is called, so deferring is correctness-safe.
        //
        // Outside a request (console commands, queue workers, tests) there is no
        // response to wait on and no terminating callback to rely on, so deliver
        // inline — otherwise the notification would silently never be sent.
        $deliver = function () use ($targets, $notification) {
            try {
                Notification::send($targets, $notification);

                // Real-time push via Reverb (bell badge) + Web Push (device).
                if (method_exists($notification, 'toArray')) {
                    foreach ($targets as $user) {
                        try {
                            $payload = $notification->toArray($user);

                            broadcast(new \App\Events\NotificationPushed(
                                userId:    $user->id,
                                title:     $payload['title'] ?? '',
                                body:      $payload['body'] ?? '',
                                actionUrl: $payload['action_url'] ?? null,
                                icon:      $payload['icon'] ?? 'bell',
                                data:      $payload['data'] ?? [],
                            ));

                            // Only staff users have PWA push subscriptions.
                            if ($user->canAccessAdmin()) {
                                WebPushService::send(
                                    userId: $user->id,
                                    title:  $payload['title'] ?? 'Bethany House',
                                    body:   $payload['body']  ?? '',
                                    url:    $payload['action_url'] ?? '/',
                                    icon:   $payload['icon'] ?? 'bell',
                                    data:   $payload['data'] ?? [],
                                );
                            }
                        } catch (\Exception $e) {
                            Log::warning('NotificationService push failed: ' . $e->getMessage(), [
                                'user_id'      => $user->id,
                                'notification' => get_class($notification),
                            ]);
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::warning('NotificationService dispatch failed: ' . $e->getMessage(), [
                    'notification' => get_class($notification),
                ]);
            }
        };

        if (app()->runningInConsole()) {
            $deliver();
        } else {
            dispatch($deliver)->afterResponse();
        }
    }

    /**
     * Resolve users by role name safely.
     *
     * WHY the raw query instead of User::role($roles):
     * Spatie's ::role() scope filters by guard_name (defaulting to 'web').
     * This app authenticates via 'sanctum', so Spatie's scope returns an
     * empty collection for every role lookup - which is why notifications
     * were never reaching admins. We query model_has_roles directly,
     * matching the same approach used in UserController::syncUserRoles().
     */
    private static function usersWithRole(string ...$roles): Collection
    {
        try {
            $roleIds = \Illuminate\Support\Facades\DB::table('roles')
                ->whereIn('name', $roles)
                ->pluck('id');

            if ($roleIds->isEmpty()) {
                return collect();
            }

            $userIds = \Illuminate\Support\Facades\DB::table('model_has_roles')
                ->whereIn('role_id', $roleIds)
                ->where('model_type', (new User())->getMorphClass())
                ->pluck('model_id')
                ->unique();

            if ($userIds->isEmpty()) {
                return collect();
            }

            return User::whereIn('id', $userIds)
                ->where('status', 'active')
                ->get();
        } catch (\Exception $e) {
            Log::warning('NotificationService::usersWithRole failed: ' . $e->getMessage());
            return collect();
        }
    }

    /**
     * Resolve a single user by ID, or null if not found.
     */
    private static function user(?int $id): ?User
    {
        if (!$id) return null;
        return User::find($id);
    }

    // ── Responsibility-based recipient groups ─────────────────────────────────
    // Business-wide roles that oversee everything, regardless of outlet. Each
    // notification goes to the group that actually acts on it (not "all admins
    // for everything"), plus outlet_manager scoped to the relevant outlet.
    private const OWNERS      = ['super_admin', 'admin'];
    private const FINANCE     = ['super_admin', 'admin', 'finance_manager'];
    private const PROCUREMENT = ['super_admin', 'admin', 'procurement_manager', 'procurement_officer'];

    /** User ids (Collection) holding ANY of the given roles, on the sanctum guard. */
    private static function userIdsForRoles(array $roles): Collection
    {
        $roleIds = DB::table('roles')->whereIn('name', $roles)->pluck('id');
        if ($roleIds->isEmpty()) return collect();

        return DB::table('model_has_roles')
            ->whereIn('role_id', $roleIds)
            ->where('model_type', (new User())->getMorphClass())
            ->pluck('model_id')
            ->unique();
    }

    /**
     * Resolve the active recipients for a role-broadcast notification.
     *
     * - `$roles` are business-wide oversight roles that always receive.
     * - `$outletId` additionally pulls in the outlet_manager(s) assigned to that
     *    outlet (via the outlet_user pivot) — so branch managers get their own
     *    branch's events without seeing every other branch.
     * - `$extraUserIds` are explicit recipients (an assignee, the customer…).
     * - The person performing the action (the authenticated user) is excluded by
     *    default — nobody is notified about their own action. Pass a non-null
     *    `$except` to override the actor, or false to disable exclusion entirely.
     *
     * Fully wrapped so a resolution error can never break the surrounding request.
     */
    private static function resolve(
        array $roles,
        ?int $outletId = null,
        array $extraUserIds = [],
        int|false|null $except = null,
    ): Collection {
        try {
            $ids = self::userIdsForRoles($roles);

            if ($outletId) {
                $atOutlet = DB::table('outlet_user')->where('outlet_id', $outletId)->pluck('user_id');
                $ids = $ids->merge(self::userIdsForRoles(['outlet_manager'])->intersect($atOutlet));
            }

            $ids = $ids->merge($extraUserIds)->filter()->unique();

            // Exclude the actor (never ping someone about their own action).
            $exceptId = $except === false ? null : ($except ?? Auth::id());
            if ($exceptId) {
                $ids = $ids->reject(fn ($id) => (int) $id === (int) $exceptId);
            }

            if ($ids->isEmpty()) return collect();

            return User::whereIn('id', $ids)->where('status', 'active')->get();
        } catch (\Throwable $e) {
            Log::warning('NotificationService::resolve failed: ' . $e->getMessage());
            return collect();
        }
    }

    /**
     * Field rights on the payload (4D): recipients are picked by role NAME,
     * but what a payload may carry is decided by PERMISSION. A role the owner
     * has edited down (an admin without payments.view) must not keep receiving
     * the figures its screens no longer show.
     */
    private static function holding(Collection $users, string $permission): Collection
    {
        return $users->filter(function (User $u) use ($permission) {
            try {
                return $u->can($permission);
            } catch (\Throwable) {
                return false;
            }
        })->values();
    }

    // ── Order notifications ───────────────────────────────────────────────────

    /**
     * Fired when any order is created (any channel).
     */
    /**
     * Someone replied to, or acknowledged, an EoD report.
     *
     * $authorUserId is the clerk who wrote the report, or null when SHE is the
     * one commenting — in which case the owners are the ones who need to hear it.
     * Routing to "the other side" is the whole point: nobody is notified of their
     * own comment, and neither side has to go looking at a reports page to find
     * out they were answered.
     */
    public static function eodReportReplied(
        ?int $authorUserId,
        int $reportId,
        string $actorName,
        string $reportDate,
        string $excerpt,
        bool $acknowledgedOnly = false
    ): void {
        $notification = new EodReportRepliedNotification(
            $reportId,
            $actorName,
            $reportDate,
            $excerpt,
            $acknowledgedOnly
        );

        if ($authorUserId) {
            $recipients = \App\Models\User::where('id', $authorUserId)->get();
        } else {
            // The clerk answered — owners are the audience.
            $recipients = self::resolve(self::OWNERS, null);
        }

        self::send($recipients, $notification);
    }

    public static function orderPlaced(int $orderId, string $orderNumber, ?int $outletId = null): void
    {
        // Owners (all branches) + the manager(s) of the outlet the order was
        // placed at. The cashier who created it isn't pinged about their own sale.
        self::send(
            self::resolve(self::OWNERS, $outletId),
            new OrderPlacedNotification($orderId, $orderNumber)
        );
    }

    /**
     * Fired when an order's status changes.
     */
    public static function orderStatusChanged(
        int $orderId,
        string $orderNumber,
        string $oldStatus,
        string $newStatus,
        ?int $customerId = null
    ): void {
        // Owners + the customer (if they have an account). The customer must
        // always be told even when they triggered it, so it's added explicitly
        // rather than via the actor-excluded role set.
        $recipients = self::resolve(self::OWNERS);
        if ($customerId) {
            $customer = self::user($customerId);
            if ($customer && $customer->status === 'active') $recipients->push($customer);
        }

        self::send(
            $recipients->unique('id'),
            new OrderStatusChangedNotification($orderId, $orderNumber, $oldStatus, $newStatus)
        );
    }

    /**
     * A storefront lead captured by Neema — notify owners so hot enquiries get
     * a fast follow-up (speed-to-lead). Best-effort; the lead is persisted
     * regardless.
     */
    public static function leadCaptured(int $leadId, string $who, string $intent): void
    {
        // A name is a summary; a phone or an email is a customer contact, and
        // this goes to lock screens and through Expo/Web Push (4D). The lead id
        // opens the record, where contact rights apply.
        if (preg_match('/\d{5,}|@/', preg_replace('/[\s()+\-.]/', '', $who))) {
            $who = 'a new contact';
        }

        self::send(
            self::resolve(self::OWNERS),
            new \App\Notifications\LeadCapturedNotification($leadId, $who, $intent)
        );
    }

    // ── Payment notifications ─────────────────────────────────────────────────

    /**
     * Fired when a payment is confirmed (gateway callback or admin manual confirmation).
     */
    public static function paymentReceived(
        int $paymentId,
        string $paymentNumber,
        int $orderId,
        string $orderNumber,
        float $amount,
        string $currency,
        string $method
    ): void {
        self::send(
            self::holding(self::resolve(self::FINANCE), 'payments.view'),
            new PaymentReceivedNotification(
                $paymentId, $paymentNumber, $orderId, $orderNumber, $amount, $currency, $method
            )
        );
    }

    /**
     * Fired when an international payment requires admin approval.
     */
    public static function paymentApprovalRequired(
        int $paymentId,
        string $paymentNumber,
        int $orderId,
        string $orderNumber,
        float $amount,
        string $currency,
        string $countryCode
    ): void {
        self::send(
            self::holding(self::resolve(self::FINANCE), 'payments.view'),
            new PaymentApprovalRequiredNotification(
                $paymentId, $paymentNumber, $orderId, $orderNumber, $amount, $currency, $countryCode
            )
        );
    }

    /**
     * Fired when a payment proof is submitted for review.
     * (Alias for paymentApprovalRequired - called from PaymentApprovalController)
     */
    public static function paymentProofSubmitted(
        int $paymentId,
        string $paymentNumber,
        string $orderNumber,
        int $orderId
    ): void {
        self::send(
            self::holding(self::resolve(self::FINANCE), 'payments.view'),
            new PaymentApprovalRequiredNotification(
                $paymentId, $paymentNumber, $orderId, $orderNumber, 0, '', ''
            )
        );
    }

    /**
     * Fired when a payment is approved by an admin.
     */
    public static function paymentApproved(
        int $paymentId,
        string $paymentNumber,
        int $orderId,
        string $orderNumber,
        ?int $notifyUserId = null
    ): void {
        $recipients = collect();
        if ($notifyUserId) {
            $user = self::user($notifyUserId);
            if ($user) $recipients->push($user);
        }

        if ($recipients->isEmpty()) {
            $recipients = self::resolve(self::FINANCE);
        }

        self::send(
            $recipients->unique('id'),
            new PaymentApprovalDecisionNotification(
                $paymentId, $paymentNumber, $orderId, $orderNumber, 'approved'
            )
        );
    }

    /**
     * Fired when a payment proof is rejected.
     */
    public static function paymentRejected(
        int $paymentId,
        string $paymentNumber,
        int $orderId,
        string $orderNumber,
        ?int $notifyUserId = null,
        string $reason = ''
    ): void {
        $recipients = collect();
        if ($notifyUserId) {
            $user = self::user($notifyUserId);
            if ($user) $recipients->push($user);
        }

        if ($recipients->isEmpty()) {
            $recipients = self::resolve(self::FINANCE);
        }

        self::send(
            $recipients->unique('id'),
            new PaymentApprovalDecisionNotification(
                $paymentId, $paymentNumber, $orderId, $orderNumber, 'rejected', $reason
            )
        );
    }

    // ── Stock notifications ───────────────────────────────────────────────────

    /**
     * Fired when tracked units have sat unsold on the shelf too long — prompts a
     * physical check that they're still there (aging = a loss-detection signal).
     */
    public static function stockAging(int $count, int $days): void
    {
        if ($count < 1) {
            return;
        }
        self::send(
            self::resolve(self::PROCUREMENT),
            new InAppNotification(
                title:     "{$count} unit(s) aging on the shelf",
                body:      "{$count} unit(s) have been in stock over {$days} days without selling. Verify they're still on the shelf.",
                actionUrl: "/inventory/serials?aged=1",
                icon:      'stock',
                data:      ['aged_count' => $count, 'days' => $days],
            ),
        );
    }

    /**
     * Fired when a product variant drops to or below its low-stock threshold.
     */
    public static function lowStockAlert(
        int $variantId,
        string $productName,
        string $sku,
        int $currentQty,
        int $threshold,
        ?string $outletName = null,
        ?int $outletManagerId = null
    ): void {
        // Low stock is a reorder trigger → procurement + owners, plus the manager
        // of the affected outlet.
        self::send(
            self::resolve(self::PROCUREMENT, null, [$outletManagerId]),
            new LowStockAlertNotification($variantId, $productName, $sku, $currentQty, $threshold, $outletName)
        );
    }

    // ── Production notifications ──────────────────────────────────────────────

    /**
     * Fired when a production order is assigned to a user.
     */
    public static function productionAssigned(
        int $productionOrderId,
        string $orderNumber,
        string $productName,
        int $assignedUserId
    ): void {
        $assignee = self::user($assignedUserId);
        if ($assignee) {
            self::send(
                $assignee,
                new ProductionAssignedNotification($productionOrderId, $orderNumber, $productName)
            );
        }
    }

    /**
     * Fired when a production stage is marked completed.
     */
    public static function productionStageCompleted(
        int $productionOrderId,
        string $orderNumber,
        string $stageName,
        string $productName
    ): void {
        self::send(
            self::resolve(self::OWNERS),
            new ProductionStageCompletedNotification(
                $productionOrderId, $orderNumber, $stageName, $productName
            )
        );
    }

    /**
     * Fired by the scheduled command for overdue production orders.
     */
    public static function productionOverdue(
        int $productionOrderId,
        string $orderNumber,
        string $productName,
        string $dueDate,
        array $assignedUserIds = []
    ): void {
        self::send(
            self::resolve(self::OWNERS, null, $assignedUserIds),
            new ProductionOverdueNotification($productionOrderId, $orderNumber, $productName, $dueDate)
        );
    }

    /**
     * Fired when a production order is cancelled: tells the tailors holding its
     * stages to stop work on it.
     */
    public static function productionCancelled(
        int $productionOrderId,
        string $orderNumber,
        string $productName,
        array $assignedUserIds,
        ?string $reason = null
    ): void {
        self::send(
            self::resolve([], null, $assignedUserIds),
            new ProductionCancelledNotification($productionOrderId, $orderNumber, $productName, $reason)
        );
    }

    // ── Shipment notifications ────────────────────────────────────────────────

    /**
     * Fired when a shipment status changes (dispatched, in transit, delivered, etc.)
     */
    public static function shipmentStatusChanged(
        int $orderId,
        string $orderNumber,
        string $newStatus,
        ?int $customerId = null
    ): void {
        $recipients = self::resolve(self::OWNERS);
        if ($customerId) {
            $customer = self::user($customerId);
            if ($customer && $customer->status === 'active') $recipients->push($customer);
        }

        self::send(
            $recipients->unique('id'),
            new ShipmentStatusChangedNotification($orderId, $orderNumber, $newStatus)
        );
    }

    // ── Purchase Order notifications ─────────────────────────────────────────

    /**
     * Fired when a purchase order is created (any status).
     */
    public static function purchaseOrderCreated(
        int $purchaseOrderId,
        string $poNumber,
        string $supplierName,
        float $totalAmount,
        string $currency = 'KES'
    ): void {
        // A PO total is supplier cost: only products.view_cost holders see it.
        $recipients = self::resolve(self::PROCUREMENT);
        $withCost   = self::holding($recipients, 'products.view_cost');
        $notice = fn (?string $amount) => new InAppNotification(
            title:     "Purchase Order {$poNumber} created",
            body:      "New PO to {$supplierName}" . ($amount !== null ? " for {$amount}" : ''),
            actionUrl: "/procurement/purchase-orders/{$purchaseOrderId}",
            icon:      'orders',
            data:      ['purchase_order_id' => $purchaseOrderId],
        );

        self::send($withCost, $notice("{$currency} " . number_format($totalAmount, 2)));
        self::send($recipients->whereNotIn('id', $withCost->pluck('id'))->values(), $notice(null));
    }

    /**
     * Fired when a PO status changes (submitted, approved, ordered, received, cancelled).
     */
    public static function purchaseOrderStatusChanged(
        int $purchaseOrderId,
        string $poNumber,
        string $oldStatus,
        string $newStatus,
        ?int $notifyUserId = null
    ): void {
        $recipients = self::resolve(self::PROCUREMENT, null, [$notifyUserId]);

        $labels = [
            'pending_approval'   => 'submitted for approval',
            'approved'           => 'approved',
            'ordered'            => 'sent to supplier',
            'partially_received' => 'partially received',
            'received'           => 'fully received',
            'cancelled'          => 'cancelled',
            'draft'              => 'returned to draft',
        ];
        $label = $labels[$newStatus] ?? $newStatus;

        self::send(
            $recipients->unique('id'),
            new InAppNotification(
                title:     "Purchase Order {$poNumber} {$label}",
                body:      "PO status changed from {$oldStatus} to {$newStatus}.",
                actionUrl: "/procurement/purchase-orders/{$purchaseOrderId}",
                icon:      'orders',
                data:      ['purchase_order_id' => $purchaseOrderId, 'old_status' => $oldStatus, 'new_status' => $newStatus],
            )
        );
    }

    /**
     * Fired when goods are received against a PO (GRN created).
     */
    public static function goodsReceived(
        int $purchaseOrderId,
        string $poNumber,
        string $grnNumber,
        string $supplierName,
        bool $fullyReceived = false
    ): void {
        $title = $fullyReceived
            ? "All goods received for PO {$poNumber}"
            : "Partial goods received for PO {$poNumber}";

        self::send(
            self::resolve(self::PROCUREMENT),
            new InAppNotification(
                title:     $title,
                body:      "GRN {$grnNumber} created. Supplier: {$supplierName}.",
                actionUrl: "/procurement/purchase-orders/{$purchaseOrderId}",
                icon:      'stock',
                data:      ['purchase_order_id' => $purchaseOrderId, 'grn_number' => $grnNumber],
            )
        );
    }

    /**
     * Fired when a purchase return is created.
     */
    public static function purchaseReturnCreated(
        int $purchaseOrderId,
        string $poNumber,
        string $returnNumber,
        string $supplierName
    ): void {
        self::send(
            self::resolve(self::PROCUREMENT),
            new InAppNotification(
                title:     "Purchase return {$returnNumber} created",
                body:      "Return against PO {$poNumber} to supplier {$supplierName}.",
                actionUrl: "/procurement/purchase-orders/{$purchaseOrderId}",
                icon:      'orders',
                data:      ['purchase_order_id' => $purchaseOrderId, 'return_number' => $returnNumber],
            )
        );
    }

    // ── Stock / Inventory notifications ──────────────────────────────────────

    /**
     * Fired when a raw material drops to/below its reorder point.
     */
    public static function rawMaterialLowStock(
        int $materialId,
        string $materialName,
        float $currentQty,
        float $reorderPoint,
        string $unit = ''
    ): void {
        self::send(
            self::resolve(self::PROCUREMENT),
            new InAppNotification(
                title:     "Low stock: {$materialName}",
                body:      "Only {$currentQty} {$unit} remaining (reorder point: {$reorderPoint} {$unit}).",
                actionUrl: "/inventory/raw-materials",
                icon:      'stock',
                data:      ['material_id' => $materialId, 'current_qty' => $currentQty],
            )
        );
    }

    /**
     * Fired when a stock adjustment requires admin approval.
     */
    /**
     * Phase 3B approval engine: a request is waiting for these people's band.
     *
     * @param  Collection<int, User>  $users
     */
    public static function approvalWaiting(Collection $users, int $requestId, string $title, ?float $amountKes): void
    {
        self::send(
            $users,
            new InAppNotification(
                title:     "Waiting for your approval: {$title}",
                body:      $amountKes !== null ? 'KES ' . number_format($amountKes, 2) . '. Open your Approvals inbox to sign or reject it.' : 'Open your Approvals inbox to sign or reject it.',
                actionUrl: '/approvals',
                icon:      'approval',
                data:      ['approval_request_id' => $requestId],
            )
        );
    }

    /** Phase 3B approval engine: tell the maker how their submission ended. */
    public static function approvalDecided(int $makerId, int $requestId, string $title, string $status, ?string $reason): void
    {
        $maker = self::user($makerId);
        if (!$maker) {
            return;
        }
        $words = match ($status) {
            'approved' => 'approved',
            'rejected' => 'rejected',
            'expired'  => 'not decided within 72 hours and came back to you',
            default    => $status,
        };
        self::send(
            $maker,
            new InAppNotification(
                title:     ucfirst("{$title} — {$words}"),
                body:      $reason ? "Reason: {$reason}" : null,
                actionUrl: '/approvals?tab=mine',
                icon:      'approval',
                data:      ['approval_request_id' => $requestId, 'status' => $status],
            )
        );
    }

    /**
     * A signer sent a record back to the people who raised it, asking for
     * changes (ApprovalEngine::requestChanges). They edit it and submit again.
     *
     * @param  int[]  $makerIds
     */
    public static function changesRequested(array $makerIds, int $requestId, string $title, string $note, ?User $by, ?string $link): void
    {
        $makers = User::whereIn('id', array_values(array_unique(array_filter($makerIds))))->get();
        if ($makers->isEmpty()) {
            return;
        }
        $who = $by ? (trim("{$by->first_name} {$by->last_name}") ?: $by->email) : null;
        self::send(
            $makers,
            new InAppNotification(
                title:     "{$title} — changes requested",
                body:      ($who ? "{$who} asks: " : 'Asked: ') . $note . ' Edit it and submit it again.',
                actionUrl: $link ?? '/approvals?tab=mine',
                icon:      'approval',
                data:      ['approval_request_id' => $requestId, 'status' => 'changes_requested'],
            )
        );
    }

    public static function stockAdjustmentPendingApproval(
        int $adjustmentId,
        string $productName,
        string $sku,
        int $quantityChange,
        string $reasonLabel,
        string $requestedByName
    ): void {
        $sign = $quantityChange > 0 ? '+' : '';
        self::send(
            self::resolve(self::PROCUREMENT),
            new InAppNotification(
                title:     "Stock adjustment requires approval",
                body:      "{$requestedByName} requested {$sign}{$quantityChange} on {$productName} ({$sku}). Reason: {$reasonLabel}.",
                actionUrl: "/inventory/adjustments",
                icon:      'stock',
                data:      ['adjustment_id' => $adjustmentId],
            )
        );
    }

    /**
     * Fired when a stock transfer is created.
     */
    public static function stockTransferCreated(
        int $transferId,
        string $transferNumber,
        string $fromOutlet,
        string $toOutlet,
        int $itemsCount
    ): void {
        self::send(
            self::resolve(self::PROCUREMENT),
            new InAppNotification(
                title:     "Stock transfer {$transferNumber} initiated",
                body:      "{$itemsCount} item(s) from {$fromOutlet} → {$toOutlet}.",
                actionUrl: "/inventory/transfers",
                icon:      'stock',
                data:      ['transfer_id' => $transferId],
            )
        );
    }

    /**
     * Fired when a stock transfer is received at destination.
     */
    public static function stockTransferCompleted(
        int $transferId,
        string $transferNumber,
        string $fromOutlet,
        string $toOutlet
    ): void {
        self::send(
            self::resolve(self::PROCUREMENT),
            new InAppNotification(
                title:     "Stock transfer {$transferNumber} received",
                body:      "Transfer from {$fromOutlet} received at {$toOutlet}.",
                actionUrl: "/inventory/transfers",
                icon:      'stock',
                data:      ['transfer_id' => $transferId],
            )
        );
    }

    // ── Production notifications (extended) ───────────────────────────────────

    /**
     * Fired when a production order is created.
     */
    public static function productionOrderCreated(
        int $productionOrderId,
        string $orderNumber,
        string $productName,
        int $quantity
    ): void {
        self::send(
            self::resolve(self::OWNERS),
            new InAppNotification(
                title:     "Production order {$orderNumber} created",
                body:      "{$quantity}x {$productName} added to production queue.",
                actionUrl: "/production/orders/{$productionOrderId}",
                icon:      'production',
                data:      ['production_order_id' => $productionOrderId],
            )
        );
    }

    /**
     * Fired when a production order is fully completed.
     */
    public static function productionOrderCompleted(
        int $productionOrderId,
        string $orderNumber,
        string $productName,
        int $quantity
    ): void {
        self::send(
            self::resolve(self::OWNERS),
            new InAppNotification(
                title:     "Production order {$orderNumber} completed",
                body:      "{$quantity}x {$productName} completed and ready.",
                actionUrl: "/production/orders/{$productionOrderId}",
                icon:      'production',
                data:      ['production_order_id' => $productionOrderId],
            )
        );
    }

    /**
     * Fired when a production order passes quality control.
     */
    public static function productionQcPassed(
        int $productionOrderId,
        string $orderNumber,
        string $productName,
        int $passedQuantity
    ): void {
        self::send(
            self::resolve(self::OWNERS),
            new InAppNotification(
                title:     "QC passed for {$orderNumber}",
                body:      "{$passedQuantity}x {$productName} passed quality control.",
                actionUrl: "/production/orders/{$productionOrderId}",
                icon:      'production',
                data:      ['production_order_id' => $productionOrderId],
            )
        );
    }

    /**
     * The QC result, told to the people who made the garment (every assignee
     * of a stage on the order). Managers get their own notice above; this one
     * is worded for the bench and opens My Tasks, where a failed order now
     * sits in its own "Failed QC" lane.
     */
    public static function productionQcResultForMakers(
        int $productionOrderId,
        string $orderNumber,
        string $productName,
        bool $passed,
        array $makerIds,
        ?string $notes = null
    ): void {
        if (! $makerIds) return;

        self::send(
            self::resolve([], null, $makerIds),
            new InAppNotification(
                title:     $passed ? "QC passed: {$productName}" : "QC failed: {$productName}",
                body:      $passed
                    ? "{$orderNumber} passed inspection."
                    : "{$orderNumber} did not pass inspection. Your manager will decide the rework."
                        . ($notes ? " Inspector's notes: {$notes}" : ''),
                actionUrl: '/production/my-tasks',
                icon:      'production',
                data:      ['production_order_id' => $productionOrderId],
            )
        );
    }

    /**
     * Fired when a production order fails quality control.
     */
    public static function productionQcFailed(
        int $productionOrderId,
        string $orderNumber,
        string $productName,
        int $failedQuantity,
        string $notes = ''
    ): void {
        self::send(
            self::resolve(self::OWNERS),
            new InAppNotification(
                title:     "QC failed for {$orderNumber}",
                body:      "{$failedQuantity}x {$productName} failed quality control." . ($notes ? " Notes: {$notes}" : ''),
                actionUrl: "/production/orders/{$productionOrderId}",
                icon:      'production',
                data:      ['production_order_id' => $productionOrderId],
            )
        );
    }

    /**
     * Fired when a user account is suspended.
     */
    /**
     * Phase 4A: a staff member hit the hourly cap on revealing customer
     * contacts. The managers of their outlets and the super admins hear of
     * it — ids and a summary only, never a customer's details.
     */
    public static function customerRevealLimitReached(User $actor, int $perHour): void
    {
        $outletIds = DB::table('outlet_user')->where('user_id', $actor->id)->pluck('outlet_id');
        $managers  = $outletIds->isEmpty() ? collect() : self::usersWithRole('outlet_manager')
            ->filter(fn (User $u) => DB::table('outlet_user')->where('user_id', $u->id)->whereIn('outlet_id', $outletIds)->exists());
        $recipients = $managers->merge(self::usersWithRole('super_admin'))
            ->unique('id')
            ->reject(fn (User $u) => $u->id === $actor->id)
            ->values();

        self::send($recipients, new InAppNotification(
            title:     'Customer reveal limit reached',
            body:      trim("{$actor->first_name} {$actor->last_name}") . " tried to reveal more than {$perHour} customer contacts in an hour and was blocked.",
            actionUrl: null,
            icon:      'shield',
            data:      ['kind' => 'customer_reveal_limit', 'user_id' => $actor->id],
        ));
    }

    public static function userSuspended(int $userId, string $reason = ''): void
    {
        $user = self::user($userId);
        if ($user) {
            self::send(
                $user,
                new InAppNotification(
                    title:     'Your account has been suspended',
                    body:      $reason ?: 'Please contact an administrator.',
                    actionUrl: null,
                    icon:      'bell',
                )
            );
        }
    }

    // ── Channel / messaging notifications (Phase 3) ──────────────────────────

    /**
     * Fired when a user is @mentioned in a channel message.
     */
    public static function channelMention(
        int    $userId,
        string $posterName,
        string $channelName,
        string $bodyPreview,
        string $actionUrl,
        int    $messageId
    ): void {
        // A mention is not an invitation (4D): someone named in a message who is
        // not in the conversation must not receive its preview.
        $channelId = DB::table('channel_messages')->where('id', $messageId)->value('channel_id');
        $isMember  = $channelId && DB::table('channel_members')
            ->where('channel_id', $channelId)->where('user_id', $userId)->exists();

        $user = $isMember ? self::user($userId) : null;
        if ($user) {
            self::send($user, new InAppNotification(
                title:     "{$posterName} mentioned you in #{$channelName}",
                body:      $bodyPreview,
                actionUrl: $actionUrl,
                icon:      'bell',
                data:      ['channel_message_id' => $messageId],
            ));
        }
    }

    /**
     * Fired for channel members who receive a new message (not @mentioned).
     * Throttled server-side: only fires if the member has no recent unread notification
     * for this channel (prevents spam when a channel is active).
     */
    public static function channelMessage(
        int    $userId,
        string $posterName,
        string $channelName,
        string $bodyPreview,
        string $actionUrl,
        int    $messageId
    ): void {
        $user = self::user($userId);
        if ($user) {
            self::send($user, new InAppNotification(
                title:     "New message in #{$channelName}",
                body:      "{$posterName}: {$bodyPreview}",
                actionUrl: $actionUrl,
                icon:      'bell',
                data:      ['channel_message_id' => $messageId],
            ));
        }
    }

    // ── User notifications ────────────────────────────────────────────────────

    /**
     * Fired when a new user account is created.
     */
    public static function userWelcome(int $userId, string $firstName): void
    {
        $user = self::user($userId);
        if ($user) {
            self::send($user, new UserWelcomeNotification($firstName));
        }
    }
}
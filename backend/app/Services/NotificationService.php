<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\OrderPlacedNotification;
use App\Notifications\OrderStatusChangedNotification;
use App\Notifications\PaymentReceivedNotification;
use App\Notifications\PaymentApprovalRequiredNotification;
use App\Notifications\PaymentApprovalDecisionNotification;
use App\Notifications\LowStockAlertNotification;
use App\Notifications\ProductionAssignedNotification;
use App\Notifications\ProductionStageCompletedNotification;
use App\Notifications\ProductionOverdueNotification;
use App\Notifications\ShipmentStatusChangedNotification;
use App\Notifications\UserWelcomeNotification;
use App\Notifications\InAppNotification;
use App\Services\WebPushService;
use Illuminate\Support\Collection;
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
        try {
            $targets = $recipients instanceof User
                ? collect([$recipients])
                : ($recipients instanceof Collection ? $recipients : collect($recipients));

            if ($targets->isEmpty()) return;

            if ($recipients instanceof User) {
                $recipients->notify($notification);
            } else {
                Notification::send($targets, $notification);
            }

            // Phase 2 - real-time push via Reverb (bell badge) + Web Push (device notification)
            if (method_exists($notification, 'toArray')) {
                foreach ($targets as $user) {
                    try {
                        $payload = $notification->toArray($user);

                        // ── Reverb broadcast - updates bell in open tab ───────
                        broadcast(new \App\Events\NotificationPushed(
                            userId:    $user->id,
                            title:     $payload['title'] ?? '',
                            body:      $payload['body'] ?? '',
                            actionUrl: $payload['action_url'] ?? null,
                            icon:      $payload['icon'] ?? 'bell',
                            data:      $payload['data'] ?? [],
                        ));

                        // ── Web Push - delivers to device when tab is closed ──
                        // Only staff users have PWA push subscriptions.
                        // Customers use the storefront, not the admin PWA.
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
            // Notifications are non-critical - log and continue
            Log::warning('NotificationService dispatch failed: ' . $e->getMessage(), [
                'notification' => get_class($notification),
            ]);
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

    // ── Order notifications ───────────────────────────────────────────────────

    /**
     * Fired when any order is created (any channel).
     */
    public static function orderPlaced(int $orderId, string $orderNumber, ?int $outletId = null): void
    {
        // Notify admins + the outlet manager of the relevant outlet
        $recipients = self::usersWithRole('admin', 'super_admin');

        if ($outletId) {
            $outletManagers = User::where('outlet_id', $outletId)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['outlet_manager']))
                ->get();
            $recipients = $recipients->merge($outletManagers);
        }

        self::send(
            $recipients->unique('id'),
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
        $recipients = collect();

        // Notify admins
        $recipients = $recipients->merge(self::usersWithRole('admin', 'super_admin'));

        // Notify the customer if they have an account
        if ($customerId) {
            $customer = self::user($customerId);
            if ($customer) $recipients->push($customer);
        }

        self::send(
            $recipients->unique('id'),
            new OrderStatusChangedNotification($orderId, $orderNumber, $oldStatus, $newStatus)
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
            self::usersWithRole('admin', 'super_admin', 'finance'),
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
            self::usersWithRole('admin', 'super_admin'),
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
            self::usersWithRole('admin', 'super_admin'),
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
            $recipients = self::usersWithRole('admin', 'super_admin');
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
            $recipients = self::usersWithRole('admin', 'super_admin');
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
        $recipients = self::usersWithRole('admin', 'super_admin');

        if ($outletManagerId) {
            $manager = self::user($outletManagerId);
            if ($manager) $recipients->push($manager);
        }

        self::send(
            $recipients->unique('id'),
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
            self::usersWithRole('admin', 'super_admin', 'production_manager'),
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
        $recipients = self::usersWithRole('admin', 'super_admin');

        foreach ($assignedUserIds as $uid) {
            $u = self::user($uid);
            if ($u) $recipients->push($u);
        }

        self::send(
            $recipients->unique('id'),
            new ProductionOverdueNotification($productionOrderId, $orderNumber, $productName, $dueDate)
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
        $recipients = collect();

        $recipients = $recipients->merge(self::usersWithRole('admin'));

        if ($customerId) {
            $customer = self::user($customerId);
            if ($customer) $recipients->push($customer);
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
        self::send(
            self::usersWithRole('admin', 'super_admin'),
            new InAppNotification(
                title:     "Purchase Order {$poNumber} created",
                body:      "New PO to {$supplierName} for {$currency} " . number_format($totalAmount, 2),
                actionUrl: "/procurement/purchase-orders/{$purchaseOrderId}",
                icon:      'orders',
                data:      ['purchase_order_id' => $purchaseOrderId],
            )
        );
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
        $recipients = self::usersWithRole('admin', 'super_admin');

        if ($notifyUserId) {
            $u = self::user($notifyUserId);
            if ($u) $recipients->push($u);
        }

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
            self::usersWithRole('admin', 'super_admin'),
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
            self::usersWithRole('admin', 'super_admin'),
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
            self::usersWithRole('admin', 'super_admin'),
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
            self::usersWithRole('admin', 'super_admin'),
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
            self::usersWithRole('admin', 'super_admin'),
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
            self::usersWithRole('admin', 'super_admin'),
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
            self::usersWithRole('admin', 'super_admin'),
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
            self::usersWithRole('admin', 'super_admin'),
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
            self::usersWithRole('admin', 'super_admin', 'production_manager'),
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
            self::usersWithRole('admin', 'super_admin', 'production_manager'),
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
        $user = self::user($userId);
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
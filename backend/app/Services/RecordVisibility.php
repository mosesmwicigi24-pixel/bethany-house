<?php

namespace App\Services;

use App\Models\GoodsReceivedNote;
use App\Models\InventoryTransfer;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\ProductionOrder;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Could this user open this record?" — one answer for every surface that
 * shows a record by reference rather than through its own module's routes.
 *
 * Comment threads and entity-preview chips are open to all staff, keyed only
 * by a type and an id. Whatever hangs off that reference — the discussion, the
 * order total and customer name on a chip — is as sensitive as the record, so
 * the test is exactly the one the record's own detail route applies:
 *
 *   Order               orders.view, through Order's ViewerScope (own-scope
 *                       cashiers see their own sales only)
 *   ProductionOrder     production.view, through ProductionOrder::visibleTo
 *                       (a tailor sees the jobs they are on)
 *   PurchaseOrder, GoodsReceivedNote, PurchaseReturn
 *                       procurement.view (the procurement route group)
 *   InventoryTransfer   inventory.view (the inventory route group)
 *   OrderReturn         orders.manage_returns (the /admin/returns group — no
 *                       other route shows a return), and its order visible
 *   EoD report          the EoD review gate (the eod-admin routes), or the
 *                       report's own author
 *
 * The permission check always comes first: DataScopeResolver answers "all"
 * when no role grants the permission, so a scoped query alone would let a
 * role WITHOUT the permission see everything.
 *
 * Eloquent's ViewerScope reads auth()->user(), so $user must be the
 * authenticated caller — which it is on every HTTP path that uses this.
 */
final class RecordVisibility
{
    /** Short type names used by chips/previews → model classes. */
    public const TYPES = [
        'order'              => Order::class,
        'production_order'   => ProductionOrder::class,
        'purchase_order'     => PurchaseOrder::class,
        'goods_received_note'=> GoodsReceivedNote::class,
        'purchase_return'    => PurchaseReturn::class,
        'order_return'       => OrderReturn::class,
        'inventory_transfer' => InventoryTransfer::class,
    ];

    public static function canView(User $user, string $modelClass, int $id): bool
    {
        return match ($modelClass) {
            Order::class => $user->can('orders.view')
                && Order::whereKey($id)->exists(),

            ProductionOrder::class => $user->can('production.view')
                && ProductionOrder::visibleTo($user)->whereKey($id)->exists(),

            PurchaseOrder::class, GoodsReceivedNote::class, PurchaseReturn::class
                => $user->can('procurement.view')
                && $modelClass::whereKey($id)->exists(),

            InventoryTransfer::class => $user->can('inventory.view')
                && InventoryTransfer::whereKey($id)->exists(),

            OrderReturn::class => $user->can('orders.manage_returns')
                && self::orderReturnVisible($id),

            default => false,
        };
    }

    /** Same question, by short type name ('order', 'production_order', 'eod_report' …). */
    public static function canViewType(User $user, string $type, int $id): bool
    {
        if ($type === 'eod_report') {
            return self::eodReportVisible($user, $id);
        }

        $class = self::TYPES[$type] ?? null;

        return $class !== null && self::canView($user, $class, $id);
    }

    private static function orderReturnVisible(int $id): bool
    {
        $return = OrderReturn::find($id);
        if (! $return) {
            return false;
        }

        // A return made against an order the caller cannot see stays hidden
        // with it. (Today every manage_returns role sees every order; this
        // keeps it true if one is ever narrowed.)
        return $return->order_id === null || Order::whereKey($return->order_id)->exists();
    }

    private static function eodReportVisible(User $user, int $id): bool
    {
        $authorId = DB::table('cash_register_eod_reports')->where('id', $id)->value('user_id');
        if ($authorId === null) {
            return false;
        }

        return (int) $authorId === (int) $user->id || $user->can('settings.view');
    }
}

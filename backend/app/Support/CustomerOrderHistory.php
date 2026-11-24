<?php

namespace App\Support;

use App\Enums\DataScope;
use App\Models\Customer;
use App\Models\Order;
use App\Models\SalesDocument;
use App\Models\User;
use App\Services\DataScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A customer's orders, as the customer record and the by-phone lookup show
 * them (2026-11-24: Neema, the chat agent, needs to know what the person it
 * is talking to has bought — at the till, in chat, or on an invoice).
 *
 * One definition of "this customer's orders" for every door: placed AS that
 * customer (orders.customer_id), or by their web account (orders.user_id).
 * CustomerController::show had it; ::customerOrders read user_id alone, and
 * for a walk-in (no web account) `user_id IS NULL` listed every other
 * walk-in's orders under them.
 *
 * HISTORY HAS ITS OWN SCOPE (review of #590, 2026-11-24). It is read
 * outside the order viewer scope and bounded by how widely the caller holds
 * `customers.insights` — the capability that IS "a customer's order history".
 * Widening who may READ a history must never ride on `orders.view`: that
 * scope is also what every order ACTION resolves an order through (payment
 * links, recording a payment), so a wider orders.view would have let a till
 * account act on other cashiers' orders.
 */
final class CustomerOrderHistory
{
    /** The most recent orders returned; the stats beside them cover all. */
    public const LIMIT = 50;

    /**
     * Every order this caller may read AS HISTORY: all of them, those of
     * their outlets, their own team's, or none — the widest data scope among
     * the roles that grant them customers.insights (DataScopeResolver).
     */
    public static function readableBy(?User $user): Builder
    {
        $orders = Order::withoutViewerScope();
        $model  = $orders->getModel();

        match (DataScopeResolver::for($user, 'customers.insights')) {
            DataScope::All    => null,
            DataScope::None   => $orders->whereRaw('1 = 0'),
            DataScope::Own    => $orders->whereIn('orders.' . $model->ownerColumn(), $user->teamIds()),
            DataScope::Outlet => $model->constrainToOutlets($orders, DataScopeResolver::outletIds($user)),
        };

        return $orders;
    }

    /** Orders placed as this customer, or by their web account. */
    public static function of(Customer $customer, ?User $viewer): Builder
    {
        return self::readableBy($viewer)->where(function ($q) use ($customer) {
            $q->where('orders.customer_id', $customer->id);
            if ($customer->user_id) {
                $q->orWhere('orders.user_id', $customer->user_id);
            }
        });
    }

    /**
     * Newest first, at most LIMIT, and whether there were more.
     *
     * @return array{0: Collection<int, Order>, 1: bool, 2: int}  orders, truncated, total
     */
    public static function recent(Builder $orders): array
    {
        $total = (clone $orders)->count();
        $rows  = (clone $orders)->with('items')
            ->orderByDesc('orders.created_at')->orderByDesc('orders.id')
            ->limit(self::LIMIT)->get();

        return [$rows, $total > $rows->count(), $total];
    }

    /**
     * Invoice numbers for these orders (an invoice is a sales document on
     * the order), keyed by order id. Voided invoices are left out.
     *
     * @param  Collection<int, Order>  $orders
     * @return array<int, string>
     */
    public static function invoiceNumbers(Collection $orders): array
    {
        if ($orders->isEmpty()) {
            return [];
        }

        return SalesDocument::query()
            ->where('type', SalesDocument::INVOICE)
            ->where('documentable_type', (new Order())->getMorphClass())
            ->whereIn('documentable_id', $orders->pluck('id'))
            ->where('status', '!=', 'void')
            ->orderBy('id')
            ->pluck('number', 'documentable_id')
            ->all();
    }

    /**
     * One order, in the shape the chat agent reads. `channel` is the sales
     * bucket (till / web / chat / quoted) beside the legacy order_type.
     */
    public static function row(Order $order, array $invoiceNumbers = []): array
    {
        return [
            'id'             => $order->id,
            'order_number'   => $order->order_number,
            'customer_id'    => $order->customer_id,
            'status'         => $order->status,
            'payment_status' => $order->payment_status,
            'order_type'     => $order->order_type,
            'channel'        => $order->sales_bucket
                ?: (Order::LEGACY_CHANNEL_MAP[$order->order_type] ?? $order->order_type),
            'invoice_number' => $invoiceNumbers[$order->id] ?? null,
            'total_amount'   => (float) $order->total_amount,
            'subtotal'       => (float) $order->subtotal,
            'currency_code'  => $order->currency_code,
            'created_at'     => $order->created_at?->toIso8601String(),
            'items'          => $order->items->map(fn ($i) => [
                'product_name' => $i->product_name,
                'quantity'     => (float) $i->quantity,
                'unit_price'   => (float) $i->unit_price,
                'total_price'  => (float) $i->total_price,
            ])->values()->all(),
        ];
    }

    /**
     * The four figures of a set of orders. Spend is recognised income only
     * (Order::scopeRecognised — the one definition every report uses), in
     * KES at each order's own reporting rate; currencies with no rate are
     * left out of the KES figure rather than converted at a guess.
     */
    public static function stats(Builder $orders): array
    {
        $recognised = (clone $orders)->recognised()
            ->whereRaw(ReportingCurrency::convertibleFilter('orders.currency_code'));
        $kes = ReportingCurrency::kes('orders.total_amount', 'orders.currency_code');

        return [
            'total_orders'        => (clone $orders)->count(),
            'recognised_orders'   => (clone $orders)->recognised()->count(),
            'total_spent'         => (float) (clone $recognised)->selectRaw("COALESCE(SUM({$kes}), 0) AS v")->value('v'),
            'average_order_value' => (float) ((clone $recognised)->selectRaw("COALESCE(AVG({$kes}), 0) AS v")->value('v') ?? 0),
            'money_basis'         => 'KES at the reporting rate',
            'last_order_date'     => (clone $orders)->orderByDesc('orders.created_at')->value('orders.created_at'),
        ];
    }
}

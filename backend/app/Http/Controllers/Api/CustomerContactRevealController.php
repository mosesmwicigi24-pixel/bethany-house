<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use App\Support\CustomerContacts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/**
 * POST /admin/customers/{id}/reveal — one contact, for one reason, tied to
 * one sale (Phase 4A, plan §9).
 *
 * A masked role (cashier, accountant) is served 07••••1853. When the work in
 * hand needs the number — chasing a balance, arranging a delivery — the
 * reveal is the way, and it is narrow on purpose:
 *
 *   - ALLOWED for the customer on an OPEN sale or shipment the caller works:
 *     the order must be visible to the caller (Order's ViewerScope — her own
 *     sale, or her outlet's for a manager), carry this customer, and be
 *     neither dead nor finished. A role that already holds the field
 *     unmasked needs no sale (the reveal is then a logged convenience).
 *   - AUDITED every time: actor, customer, field, reason, context, IP and
 *     session (token id). Never the value.
 *   - CAPPED at 20 an hour per user. The 21st is refused, and the caller's
 *     outlet manager(s) and the super admins are told — once an hour, not
 *     once per refused attempt.
 *
 * Refusals are audited too: a stream of them is exactly what someone
 * harvesting numbers looks like.
 */
class CustomerContactRevealController extends Controller
{
    public const REASONS = ['payment_follow_up', 'delivery', 'customer_callback', 'order_issue'];

    public const PER_HOUR = 20;

    /** A sale that is still being worked: not dead, not finished. */
    private const CLOSED_STATUSES = ['completed', 'delivered'];

    public function reveal(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'field'        => ['required', Rule::in(['phone', 'email', 'address'])],
            'reason'       => ['required', Rule::in(self::REASONS)],
            'context'      => ['nullable', 'array'],
            'context.type' => ['required_with:context', Rule::in(['order', 'shipment'])],
            'context.id'   => ['required_with:context', 'integer'],
        ]);

        $user     = $request->user();
        $customer = Customer::findOrFail($id);
        $field    = $validated['field'];
        $context  = isset($validated['context'])
            ? ['type' => $validated['context']['type'], 'id' => (int) $validated['context']['id']]
            : null;

        $policy   = CustomerContacts::policyFor($user, ['customers.view', 'orders.view']);
        $heldFull = ($field === 'address' ? $policy['address'] : $policy['contacts']) === CustomerContacts::FULL;

        if (!$heldFull && $context === null) {
            return response()->json([
                'message' => 'Say which sale or shipment you need this for.',
                'errors'  => ['context' => ['A reveal is tied to the open sale or shipment you are working on.']],
            ], 422);
        }

        $order = $context ? $this->openWorkFor($context, $customer) : null;
        if (!$heldFull && $order === null) {
            $this->audit('customer_contact_reveal_refused', $request, $customer, $field, $validated['reason'], $context);

            return response()->json([
                'code'    => 'reveal_not_allowed',
                'message' => 'You can reveal a customer\'s details only for an open sale or shipment of yours with that customer.',
            ], 403);
        }

        $key = 'customer-reveal:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, self::PER_HOUR)) {
            $this->audit('customer_contact_reveal_blocked', $request, $customer, $field, $validated['reason'], $context);
            $this->reportLimit($user);

            return response()->json([
                'code'        => 'reveal_limit',
                'message'     => 'You have revealed ' . self::PER_HOUR . ' customer details in the last hour. Ask your manager if you need more.',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }
        RateLimiter::hit($key, 3600);

        $this->audit('customer_contact_revealed', $request, $customer, $field, $validated['reason'], $context);

        return response()->json([
            'field' => $field,
            'value' => $this->valueOf($field, $customer, $order),
        ]);
    }

    /**
     * The open sale (or the sale behind an open shipment) this customer is on,
     * as far as THIS caller can see it — Order::query() carries the viewer
     * scope, so "works" is the caller's own sale, or their outlet's.
     */
    private function openWorkFor(array $context, Customer $customer): ?Order
    {
        $orderId = $context['id'];
        if ($context['type'] === 'shipment') {
            $shipment = DB::table('order_shipments')->where('id', $context['id'])
                ->whereNotIn('status', ['delivered', 'cancelled'])->first();
            if (!$shipment) {
                return null;
            }
            $orderId = (int) $shipment->order_id;
        }

        return Order::query()
            ->whereKey($orderId)
            ->where(fn ($q) => $q->where('customer_id', $customer->id)
                ->when($customer->user_id, fn ($w) => $w->orWhere('user_id', $customer->user_id)))
            ->whereNotIn('status', array_merge(Order::DEAD_STATUSES, $context['type'] === 'order' ? self::CLOSED_STATUSES : []))
            ->first();
    }

    private function valueOf(string $field, Customer $customer, ?Order $order): mixed
    {
        return match ($field) {
            'phone'   => $customer->phone ?: $order?->customer_phone,
            'email'   => $customer->email ?: $order?->customer_email,
            'address' => $this->address($customer, $order),
        };
    }

    /** The delivery address: the sale's own, else the customer's default. */
    private function address(Customer $customer, ?Order $order): ?array
    {
        if ($order && $order->shipping_address_line1) {
            return [
                'line1'        => $order->shipping_address_line1,
                'line2'        => $order->shipping_address_line2,
                'city'         => $order->shipping_city,
                'postal_code'  => $order->shipping_postal_code,
                'country_code' => $order->shipping_country_code,
            ];
        }

        $a = DB::table('addresses')->where('customer_id', $customer->id)
            ->orderByDesc('is_default')->orderByDesc('id')->first();

        return $a ? [
            'line1'        => $a->address_line1,
            'line2'        => $a->address_line2,
            'city'         => $a->city,
            'postal_code'  => $a->postal_code,
            'country_code' => $a->country_code,
        ] : null;
    }

    private function audit(string $event, Request $request, Customer $customer, string $field, string $reason, ?array $context): void
    {
        ActivityLogService::log($event, $customer, [
            'field'   => $field,
            'reason'  => $reason,
            'context' => $context,
            'ip'      => $request->ip(),
            // The Sanctum token is the session: it is what a revoke ends.
            'session' => $this->sessionId($request),
        ], match ($event) {
            'customer_contact_revealed'       => "Revealed a customer's {$field} ({$reason})",
            'customer_contact_reveal_blocked' => "Reveal blocked: more than " . self::PER_HOUR . " in an hour",
            default                           => "Reveal refused: no open sale with this customer",
        }, $request->user());
    }

    /** The token id this request rode on (null for a transient/test token). */
    private function sessionId(Request $request): ?int
    {
        try {
            $token = $request->user()?->currentAccessToken();

            return $token instanceof \Laravel\Sanctum\PersonalAccessToken && $token->exists ? (int) $token->getKey() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Tell the caller's outlet manager(s) and the super admins, once an hour. */
    private function reportLimit(User $user): void
    {
        if (!Cache::add('customer-reveal-reported:' . $user->id, true, 3600)) {
            return;
        }

        NotificationService::customerRevealLimitReached($user, self::PER_HOUR);
    }
}

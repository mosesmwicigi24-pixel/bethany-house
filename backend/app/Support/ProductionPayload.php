<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Support\Arrayable;

/**
 * What a production payload may say about the customer and the cost, per
 * viewer.
 *
 * Owner's field rule for the shop floor: a tailor works from the order
 * reference, garment spec, measurements, materials and quantities,
 * instructions, deadline, QC and status — and knows the customer by FIRST
 * NAME. A customer's phone, email and surname are for roles holding
 * customers.view; what materials cost is for products.view_cost.
 *
 * Production orders are serialised in several shapes (list, detail, a task's
 * production_order, the tailor's task list) and all of them carry the same
 * three customer routes: the appended customer_label / customer_contact, the
 * `customer` relation, and the sales order's snapshot columns under
 * `customer_order`. So the rule keys off the shape of a production order —
 * any array carrying `customer_label` — wherever it sits in the payload,
 * rather than off each endpoint.
 */
final class ProductionPayload
{
    /** The payload as this viewer may see it. */
    public static function forViewer(mixed $payload, ?User $user): mixed
    {
        if ($payload instanceof Arrayable) {
            $payload = $payload->toArray();
        }

        // Literal permission name: permission:audit finds enforcement by
        // scanning for ->can('…').
        if (! ($user !== null && $user->can('customers.view'))) {
            $payload = self::withoutContacts($payload);
        }

        return CostVisibility::forViewer($payload, $user);
    }

    /** Reduce every production order in the payload to first-name identity. */
    private static function withoutContacts(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_key_exists('customer_label', $value)) {
            $value = self::redactOrder($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::withoutContacts($item);
            }
        }

        return $value;
    }

    /** @param array<string,mixed> $order */
    private static function redactOrder(array $order): array
    {
        // First name, resolved in the same precedence as customer_label:
        // the job's own customer, then the sales order's snapshot, then the
        // sales order's customer record.
        $first = self::firstNonEmpty([
            $order['customer']['first_name'] ?? null,
            $order['customer_order']['customer_first_name'] ?? null,
            $order['customer_order']['customer']['first_name'] ?? null,
        ]);
        if ($first === null && ! empty($order['customer_label'])) {
            // Only a surname on record: the first word is all that is shown.
            $first = strtok((string) $order['customer_label'], ' ') ?: null;
        }

        $order['customer_label']   = $order['customer_label'] === null ? null : $first;
        $order['customer_contact'] = null;

        if (isset($order['customer']) && is_array($order['customer'])) {
            $order['customer'] = self::person($order['customer']);
        }

        if (isset($order['customer_order']) && is_array($order['customer_order'])) {
            $co = $order['customer_order'];
            unset($co['customer_last_name'], $co['customer_phone'], $co['customer_email']);
            if (array_key_exists('customer_name', $co)) {
                $co['customer_name'] = $co['customer_first_name'] ?? null;
            }
            if (isset($co['customer']) && is_array($co['customer'])) {
                $co['customer'] = self::person($co['customer']);
            }
            $order['customer_order'] = $co;
        }

        return $order;
    }

    /** A customer record reduced to who, by first name. */
    private static function person(array $customer): array
    {
        return array_intersect_key($customer, array_flip(['id', 'first_name']));
    }

    /** @param list<mixed> $candidates */
    private static function firstNonEmpty(array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if (is_string($c) && trim($c) !== '') {
                return trim($c);
            }
        }

        return null;
    }

    /** A display name cut to its first word — for already-flattened rows. */
    public static function firstNameOf(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return $name;
        }

        return strtok(trim($name), ' ') ?: null;
    }
}

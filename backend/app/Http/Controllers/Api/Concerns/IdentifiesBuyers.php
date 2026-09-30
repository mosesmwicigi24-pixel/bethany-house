<?php

namespace App\Http\Controllers\Api\Concerns;

/**
 * ONE answer to "who bought this".
 *
 * This repository's most persistent defect, seven instances and counting:
 *
 *   #377            the customer page listed a customer's orders by login
 *   cycle 1 (#380)  the customer summary counted buyers by login — all zeros
 *   cycle 3 (#382)  Sales by Customer and Lifetime Value were EMPTY PAGES;
 *                   active customers read 0; every customer segmented "New"
 *   cycle 4         the exported PDF's top-customers table and average
 *                   lifetime value, same cause
 *
 * The reason it kept recurring is written in the code itself. Above one of the
 * PDF queries sits the comment "group by user_id (orders have no
 * customer_id)" — which was TRUE when it was written. The column was added
 * later and backfilled (592 links), and nothing went back to re-derive the
 * queries that had encoded the old shape. Not forgetfulness: a stale
 * assumption that no test was watching.
 *
 * Measured on production 2026-09-30: **not one of the 623 paid orders has a
 * user_id**, and 683 of 684 customers have no login at all, because almost
 * every sale is a walk-in served at the counter. Any report keyed on the login
 * is therefore not "mostly right" — it is empty.
 *
 * A buyer is the CUSTOMER record, with the web login as a second arm. The two
 * id spaces are prefixed so customer 1 and user 1 can never collapse into one
 * person, and the expression is NULL only for an order attached to nobody at
 * all (219 such orders exist; they are sales, but they are not a buyer anyone
 * can name, and they must not be counted as one).
 */
trait IdentifiesBuyers
{
    private function buyerKey(string $table = 'orders'): string
    {
        return "COALESCE('c' || {$table}.customer_id, 'u' || {$table}.user_id)";
    }
}

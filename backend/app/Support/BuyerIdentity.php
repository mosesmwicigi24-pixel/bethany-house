<?php

namespace App\Support;

/**
 * WHO bought — one definition for every report (owner, 2026-10-01: phone first).
 *
 *   the order's phone, else the customer record's phone  (as full E.164)
 *   → the customer record → the email → the login
 *
 * Cycle 10 found four definitions in the module, and four answers for the same
 * 207 September orders: 173 buyers on Sales by Customer (record, else login),
 * 184 on the sales summary and its PDF (login, else phone, else email — never
 * the record), 187 on second purchase, 192 on the engine pages (record, else
 * the RAW phone, so "0711…" and "+254711…" were two people). Sales by product
 * counted logins only — and almost no order has one.
 *
 * Phone first because a phone is how this business knows a person: a walk-in
 * at the till and the same customer's registered account are one buyer (62
 * walk-in orders carry a registered customer's phone), and 38 phone numbers
 * sit on more than one customer record — duplicate registrations of one
 * person. The record's own phone is read by primary key, so a registered
 * customer's orders with no phone typed still resolve to the same buyer.
 *
 * The one deliberate exception is the institutions report, which groups by
 * ACCOUNT (a church whose buying staff change) — a different question from
 * "how many people bought". See MetricEngine::computeInstitutionalAccounts.
 *
 * Prefixes keep the kinds apart: '+…' phones, 'c' records, emails, 'u' logins.
 * An order with none of the four (an anonymous till sale) has no identity and
 * is not counted as a distinct buyer.
 */
final class BuyerIdentity
{
    public static function sql(string $order = 'orders'): string
    {
        return "COALESCE("
            . "normalize_phone(COALESCE(NULLIF({$order}.customer_phone, ''), "
            . "(SELECT bi_c.phone FROM customers bi_c WHERE bi_c.id = {$order}.customer_id))), "
            . "'c' || {$order}.customer_id, "
            . "LOWER(NULLIF(btrim({$order}.customer_email), '')), "
            . "'u' || {$order}.user_id)";
    }
}

<?php

namespace App\Services\Neema;

/**
 * Reads Neema's cross-channel analytics. Abstracted behind an interface so the
 * sync command runs against a fake in tests (no network/secrets).
 */
interface NeemaAnalyticsClient
{
    /**
     * Per-person × per-channel message rollup, keyed by phone.
     * Returns rows: [{ phone, channel, messages, inbound, first_at, last_at }].
     * Returns [] when Neema is not configured or unreachable.
     */
    public function messageRollup(int $sinceDays = 365): array;
}

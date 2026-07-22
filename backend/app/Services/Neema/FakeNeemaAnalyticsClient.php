<?php

namespace App\Services\Neema;

/**
 * Test double — returns a preset rollup, no network. Bind it in tests:
 *   $this->app->instance(NeemaAnalyticsClient::class, new FakeNeemaAnalyticsClient($rows));
 */
class FakeNeemaAnalyticsClient implements NeemaAnalyticsClient
{
    public function __construct(private array $rows = []) {}

    public function messageRollup(int $sinceDays = 365): array
    {
        return $this->rows;
    }
}

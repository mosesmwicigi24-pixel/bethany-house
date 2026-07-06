<?php

namespace Tests\Unit;

use App\Support\SortResolver;
use PHPUnit\Framework\TestCase;

/**
 * Guards the fix for the `sort_by` SQL-injection in the Orders/Shipments list
 * endpoints. Column identifiers cannot be parameter-bound, so the resolver must
 * reject anything outside the allow-list and normalise the direction.
 */
class SortResolverTest extends TestCase
{
    private array $allowed = ['created_at', 'total_amount', 'status'];

    public function test_allowed_column_passes_through(): void
    {
        [$col, $dir] = SortResolver::resolve('total_amount', 'asc', $this->allowed, 'created_at');

        $this->assertSame('total_amount', $col);
        $this->assertSame('asc', $dir);
    }

    public function test_unknown_column_falls_back_to_default(): void
    {
        [$col] = SortResolver::resolve('nonexistent_column', 'asc', $this->allowed, 'created_at');

        $this->assertSame('created_at', $col);
    }

    public function test_sql_injection_payload_is_rejected(): void
    {
        $payload = 'id); DROP TABLE users; --';

        [$col, $dir] = SortResolver::resolve($payload, 'desc', $this->allowed, 'created_at');

        $this->assertSame('created_at', $col);
        $this->assertNotSame($payload, $col);
        $this->assertSame('desc', $dir);
    }

    public function test_null_inputs_fall_back_to_safe_defaults(): void
    {
        [$col, $dir] = SortResolver::resolve(null, null, $this->allowed, 'created_at');

        $this->assertSame('created_at', $col);
        $this->assertSame('desc', $dir);
    }

    public function test_direction_is_normalised_to_asc_or_desc(): void
    {
        $this->assertSame('asc', SortResolver::resolve('status', 'asc', $this->allowed, 'created_at')[1]);
        $this->assertSame('asc', SortResolver::resolve('status', 'ASC', $this->allowed, 'created_at')[1]);
        $this->assertSame('desc', SortResolver::resolve('status', 'desc', $this->allowed, 'created_at')[1]);
        // Anything that isn't 'asc' — including an injection attempt on the
        // direction — collapses to the literal 'desc'.
        $this->assertSame('desc', SortResolver::resolve('status', 'desc; DROP TABLE users', $this->allowed, 'created_at')[1]);
        $this->assertSame('desc', SortResolver::resolve('status', 'garbage', $this->allowed, 'created_at')[1]);
    }
}

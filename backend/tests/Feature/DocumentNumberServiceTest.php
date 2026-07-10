<?php

namespace Tests\Feature;

use App\Services\DocumentNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Gapless, per-type, sequential document numbering — the legal backbone of the
 * quotation → invoice → receipt flow (KRA requires gapless tax-invoice numbers).
 */
class DocumentNumberServiceTest extends TestCase
{
    use RefreshDatabase;

    private function year(): string
    {
        return now()->format('Y');
    }

    public function test_numbers_are_sequential_within_a_type(): void
    {
        $y = $this->year();
        $this->assertSame("INV-{$y}-0001", DocumentNumberService::next('invoice'));
        $this->assertSame("INV-{$y}-0002", DocumentNumberService::next('invoice'));
        $this->assertSame("INV-{$y}-0003", DocumentNumberService::next('invoice'));
    }

    public function test_types_have_independent_sequences(): void
    {
        $y = $this->year();
        $this->assertSame("INV-{$y}-0001", DocumentNumberService::next('invoice'));
        $this->assertSame("QUO-{$y}-0001", DocumentNumberService::next('quotation'));
        $this->assertSame("RCP-{$y}-0001", DocumentNumberService::next('receipt'));
        $this->assertSame("INV-{$y}-0002", DocumentNumberService::next('invoice'));
    }

    public function test_period_can_be_overridden(): void
    {
        $this->assertSame('INV-2025-0001', DocumentNumberService::next('invoice', '2025'));
        $this->assertSame('INV-2025-0002', DocumentNumberService::next('invoice', '2025'));
        // A different period is an independent series.
        $this->assertSame('INV-2024-0001', DocumentNumberService::next('invoice', '2024'));
    }

    public function test_a_number_is_not_consumed_when_the_issue_rolls_back(): void
    {
        $y = $this->year();

        // Allocate a number inside a transaction that then fails — the number must
        // NOT be burned (no gaps), unlike a Postgres sequence.
        try {
            DB::transaction(function () {
                DocumentNumberService::next('invoice');
                throw new \RuntimeException('issue failed after numbering');
            });
        } catch (\RuntimeException) {
            // expected
        }

        // The next successful allocation still starts at 0001.
        $this->assertSame("INV-{$y}-0001", DocumentNumberService::next('invoice'));
    }

    public function test_unknown_type_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DocumentNumberService::next('credit_note');
    }
}

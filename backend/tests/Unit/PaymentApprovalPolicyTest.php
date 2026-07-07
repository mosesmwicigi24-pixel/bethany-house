<?php

namespace Tests\Unit;

use App\Models\PaymentMethod;
use PHPUnit\Framework\TestCase;

/**
 * PaymentMethod::deriveRequiresApproval is the single source of truth for the
 * per-method approval policy, shared by createSale, recordPosPay, addPayment and
 * mirrored to the client. This locks the policy down:
 *
 *   - the explicit per-method flag always wins;
 *   - when the flag is NULL (un-configured), we fall back to the legacy type
 *     derivation so old rows never change behaviour: cash / I&M / gateway rails
 *     settle instantly, everything else is held for review.
 */
class PaymentApprovalPolicyTest extends TestCase
{
    public function test_the_configured_flag_is_authoritative(): void
    {
        // Flag wins even when the type derivation would say otherwise.
        // A cash method explicitly marked as needing approval → approval.
        $this->assertTrue(PaymentMethod::deriveRequiresApproval('cash', 'cash', true));
        // A bank transfer explicitly marked as instant → no approval.
        $this->assertFalse(PaymentMethod::deriveRequiresApproval('bank_transfer', 'bank_transfer', false));
    }

    public function test_instant_and_gateway_methods_need_no_approval_when_unconfigured(): void
    {
        // Built-in cash.
        $this->assertFalse(PaymentMethod::deriveRequiresApproval('cash', 'cash', null));
        // I&M Paybill — settles instantly; typed as cash, no flag set.
        $this->assertFalse(PaymentMethod::deriveRequiresApproval('inmpaybill', 'cash', null));
        // Gateway rails by code.
        $this->assertFalse(PaymentMethod::deriveRequiresApproval('mpesa', 'mobile_money', null));
        $this->assertFalse(PaymentMethod::deriveRequiresApproval('card_paystack', 'card', null));
        // Gateway rails by type only (unknown code).
        $this->assertFalse(PaymentMethod::deriveRequiresApproval('someprovider', 'mobile_money', null));
        $this->assertFalse(PaymentMethod::deriveRequiresApproval('someprovider', 'card', null));
    }

    public function test_manual_rails_need_approval_when_unconfigured(): void
    {
        // Bank transfer by type.
        $this->assertTrue(PaymentMethod::deriveRequiresApproval('bank_transfer', 'bank_transfer', null));
        // Cheque / Western Union / MoneyGram: unknown type → manual → approval.
        $this->assertTrue(PaymentMethod::deriveRequiresApproval('cheque', 'bank_transfer', null));
        $this->assertTrue(PaymentMethod::deriveRequiresApproval('western_union', null, null));
        $this->assertTrue(PaymentMethod::deriveRequiresApproval('moneygram', null, null));
        // The generic "other" tender always needs review.
        $this->assertTrue(PaymentMethod::deriveRequiresApproval('other', 'other', null));
    }

    public function test_inmpaybill_can_be_forced_to_approval_via_the_flag(): void
    {
        // If the owner ever wants I&M gated, the flag overrides the instant default.
        $this->assertTrue(PaymentMethod::deriveRequiresApproval('inmpaybill', 'cash', true));
    }
}

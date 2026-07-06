<?php

namespace Tests\Unit;

use App\Support\OrderStatusMachine;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Audit ST-1: legal order status transitions.
 */
class OrderStatusMachineTest extends TestCase
{
    public function test_forward_transitions_are_allowed(): void
    {
        $this->assertTrue(OrderStatusMachine::canTransition('pending', 'processing'));
        $this->assertTrue(OrderStatusMachine::canTransition('processing', 'shipped'));
        $this->assertTrue(OrderStatusMachine::canTransition('confirmed', 'delivered'));
        $this->assertTrue(OrderStatusMachine::canTransition('delivered', 'completed'));
        $this->assertTrue(OrderStatusMachine::canTransition('completed', 'refunded'));
    }

    public function test_processing_and_confirmed_move_laterally(): void
    {
        $this->assertTrue(OrderStatusMachine::canTransition('confirmed', 'processing'));
        $this->assertTrue(OrderStatusMachine::canTransition('processing', 'confirmed'));
    }

    public function test_backward_transitions_are_blocked(): void
    {
        $this->assertFalse(OrderStatusMachine::canTransition('delivered', 'pending'));
        $this->assertFalse(OrderStatusMachine::canTransition('shipped', 'processing'));
        $this->assertFalse(OrderStatusMachine::canTransition('completed', 'processing'));
    }

    public function test_terminal_states_cannot_be_left(): void
    {
        $this->assertFalse(OrderStatusMachine::canTransition('cancelled', 'shipped'));
        $this->assertFalse(OrderStatusMachine::canTransition('cancelled', 'pending'));
        $this->assertFalse(OrderStatusMachine::canTransition('refunded', 'completed'));
    }

    public function test_completed_may_only_go_to_refunded(): void
    {
        $this->assertTrue(OrderStatusMachine::canTransition('completed', 'refunded'));
        $this->assertFalse(OrderStatusMachine::canTransition('completed', 'delivered'));
        $this->assertFalse(OrderStatusMachine::canTransition('completed', 'cancelled'));
    }

    public function test_same_status_is_a_noop_and_allowed(): void
    {
        $this->assertTrue(OrderStatusMachine::canTransition('delivered', 'delivered'));
    }

    public function test_unknown_current_status_fails_open(): void
    {
        $this->assertTrue(OrderStatusMachine::canTransition('some_legacy_state', 'pending'));
    }

    public function test_assert_throws_422_on_illegal_transition(): void
    {
        try {
            OrderStatusMachine::assertCanTransition('delivered', 'pending');
            $this->fail('Expected an HttpException for an illegal transition.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_assert_passes_on_legal_transition(): void
    {
        OrderStatusMachine::assertCanTransition('pending', 'processing');
        $this->addToAssertionCount(1); // reached here → no exception thrown
    }
}

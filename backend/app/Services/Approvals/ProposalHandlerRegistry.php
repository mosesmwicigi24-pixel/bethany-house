<?php

namespace App\Services\Approvals;

/**
 * Puts the Phase 3C proposal handlers on the approval engine.
 *
 * Why this class exists: the engine builds its handler table from a list in
 * its own constructor and offers no way to add to it. 3C was built against a
 * snapshot of the 3B engine and must not edit it, so this adds the handlers
 * from outside, once per resolved engine (App\Providers\ProposalServiceProvider
 * hooks afterResolving). It never replaces a handler the engine already has.
 *
 * When 3B's final engine lands, the clean form is one line there — these
 * classes in the constructor list, or a public register() — and this class and
 * its provider are deleted.
 */
final class ProposalHandlerRegistry
{
    /** @var list<class-string<Handlers\ProposalHandler>> */
    public const HANDLERS = [
        Handlers\SellingPriceChangeHandler::class,
        Handlers\ProductCostChangeHandler::class,
        Handlers\SupplierCostChangeHandler::class,
        Handlers\TaxRateChangeHandler::class,
        Handlers\ReportingFxChangeHandler::class,
        Handlers\PaymentSettlementChangeHandler::class,
        Handlers\CustomerPricingFxChangeHandler::class,
        Handlers\CustomerCreditHandler::class,
    ];

    public static function registerInto(ApprovalEngine $engine): void
    {
        $handlers = array_map(fn ($class) => app($class), self::HANDLERS);

        // The engine's table is private; bind a closure into its scope to add
        // to it — the narrowest reach that leaves the engine's code untouched.
        \Closure::bind(function (array $handlers) {
            foreach ($handlers as $handler) {
                $this->handlers[$handler->event()] ??= $handler;
            }
        }, $engine, ApprovalEngine::class)($handlers);
    }
}

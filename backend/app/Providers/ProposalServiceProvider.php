<?php

namespace App\Providers;

use App\Services\Approvals\ApprovalEngine;
use App\Services\Approvals\ProposalHandlerRegistry;
use Illuminate\Support\ServiceProvider;

/** Phase 3C: every resolved approval engine also knows the proposal events. */
class ProposalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(ApprovalEngine::class, function (ApprovalEngine $engine) {
            ProposalHandlerRegistry::registerInto($engine);
        });
    }
}

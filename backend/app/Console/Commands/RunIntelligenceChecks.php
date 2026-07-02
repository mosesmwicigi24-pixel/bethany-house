<?php

namespace App\Console\Commands;

use App\Services\IntelligenceService;
use App\Models\User;
use App\Notifications\BudgetExceededNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * RunIntelligenceChecks
 *
 * Scheduled nightly command that runs all passive intelligence checks:
 *   - Expense budget warnings (notifies finance managers)
 *   - Churn risk summary (logs count for dashboard widget)
 *   - Material shortage summary (logs for production managers)
 *
 * Schedule: daily at 07:00 (before the workday starts)
 *
 * Register in app/Console/Kernel.php:
 *   $schedule->command('intelligence:run')->dailyAt('07:00');
 *
 * Run manually:
 *   php artisan intelligence:run
 */
class RunIntelligenceChecks extends Command
{
    protected $signature   = 'intelligence:run {--dry-run : Print results without sending notifications}';
    protected $description = 'Run nightly intelligence checks: budget warnings, churn risk, material shortages';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $this->info('[Intelligence] Starting checks at ' . now()->toDateTimeString());

        // ── 1. Expense budget warnings ────────────────────────────────────────
        $this->line('  Checking expense budgets…');
        try {
            $warnings = IntelligenceService::expenseBudgetWarnings();

            if (empty($warnings)) {
                $this->info('  ✓ No budget warnings');
            } else {
                $exceeded = array_filter($warnings, fn ($w) => $w['severity'] === 'exceeded');
                $warning  = array_filter($warnings, fn ($w) => $w['severity'] === 'warning');

                $this->warn("  ⚠ {$warnings[0]['category_name']} and " . (count($warnings) - 1) . " others need attention");
                $this->line('  Exceeded: ' . count($exceeded) . ' | Warning: ' . count($warning));

                if (!$dryRun) {
                    // Notify all finance managers / approvers
                    $approvers = User::whereHas('roles.permissions', function ($q) {
                        $q->where('permissions.name', 'expenses.approve')
                          ->where('permissions.guard_name', 'sanctum');
                    })->get();

                    foreach ($exceeded as $w) {
                        foreach ($approvers as $approver) {
                            try {
                                $approver->notify(new BudgetExceededNotification(
                                    $w['budget_id'],
                                    $w['category_name'],
                                    $w['budgeted_amount'],
                                    $w['actual_spend'],
                                    $w['utilization_percent']
                                ));
                            } catch (\Exception $e) {
                                Log::warning('[Intelligence] Budget notification failed: ' . $e->getMessage());
                            }
                        }
                    }
                    $this->info('  ✓ Notified ' . $approvers->count() . ' approvers about ' . count($exceeded) . ' exceeded budgets');
                }
            }
        } catch (\Exception $e) {
            $this->error('  ✗ Budget check failed: ' . $e->getMessage());
            Log::error('[Intelligence] Budget check error: ' . $e->getMessage());
        }

        // ── 2. Churn risk ─────────────────────────────────────────────────────
        $this->line('  Checking customer churn risk…');
        try {
            $atRisk = IntelligenceService::churnRiskCustomers(100);
            $high   = array_filter($atRisk, fn ($c) => $c['risk_level'] === 'high');
            $this->info('  ✓ At-risk customers: ' . count($atRisk) . ' (' . count($high) . ' high risk)');

            Log::info('[Intelligence] Churn risk check', [
                'total_at_risk' => count($atRisk),
                'high_risk'     => count($high),
            ]);
        } catch (\Exception $e) {
            $this->error('  ✗ Churn risk check failed: ' . $e->getMessage());
        }

        // ── 3. Material shortages ─────────────────────────────────────────────
        $this->line('  Checking material shortages across production queue…');
        try {
            $shortages = IntelligenceService::materialShortagePreFlight();
            if (empty($shortages)) {
                $this->info('  ✓ No material shortages detected');
            } else {
                $outOfStock = array_filter($shortages, fn ($s) => $s['severity'] === 'out_of_stock');
                $this->warn('  ⚠ ' . count($shortages) . ' material shortage(s) detected (' . count($outOfStock) . ' out of stock)');

                if (!$dryRun) {
                    Log::warning('[Intelligence] Material shortages detected', [
                        'count'       => count($shortages),
                        'out_of_stock'=> count($outOfStock),
                        'materials'   => array_column($shortages, 'material_name'),
                    ]);
                }
            }
        } catch (\Exception $e) {
            $this->error('  ✗ Material shortage check failed: ' . $e->getMessage());
        }

        // ── 4. Reorder suggestions ────────────────────────────────────────────
        $this->line('  Checking reorder suggestions…');
        try {
            $items = \App\Models\InventoryItem::whereHas('product', fn ($q) => $q->where('status', 'active'))
                ->where(function ($q) {
                    $q->where('quantity_on_hand', '<=', 0)
                      ->orWhereRaw('reorder_point > 0 AND quantity_on_hand <= reorder_point');
                })->count();

            $this->info("  ✓ Items needing reorder: {$items}");
        } catch (\Exception $e) {
            $this->error('  ✗ Reorder check failed: ' . $e->getMessage());
        }

        $this->info('[Intelligence] All checks complete at ' . now()->toDateTimeString());
        return self::SUCCESS;
    }
}
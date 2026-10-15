<?php

namespace App\Console\Commands;

use App\Models\CashRegister;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * The morning check's reminders (owner, 2026-10-15). A till is never closed
 * by the system — a drawer count cannot be invented — so instead:
 *
 *   --evening  (21:30) each cashier whose till opened TODAY is still open is
 *              reminded to report and count it before leaving;
 *   --morning  (07:45) the owner and each outlet's manager get the list of
 *              tills left open from an earlier day, whose cashiers will meet
 *              the pop-up and cannot sell until they close them.
 *
 * Both are read-only over cash_registers. Off with pos.same_day_till_required.
 */
class RemindOpenTills extends Command
{
    protected $signature = 'tills:remind-open {--evening} {--morning}';

    protected $description = 'Remind cashiers to close their till, and managers of tills left open overnight';

    public function handle(): int
    {
        if (!config('pos.same_day_till_required', true)) {
            $this->info('Same-day till rule is off; nothing sent.');
            return self::SUCCESS;
        }

        if ($this->option('evening')) {
            $open = CashRegister::with(['outlet:id,name', 'openedBy'])
                ->where('status', 'open')
                ->where('opened_at', '>=', today())
                ->get();
            foreach ($open as $till) {
                if ($till->openedBy && $till->openedBy->status === 'active') {
                    NotificationService::tillStillOpen($till->openedBy, $till->outlet?->name ?? 'your outlet', $till->opened_at->toDateString());
                }
            }
            $this->info("Evening reminders: {$open->count()}");
        }

        if ($this->option('morning')) {
            $stale = CashRegister::with(['outlet:id,name', 'openedBy:id,first_name,last_name,email'])
                ->where('status', 'open')
                ->where('opened_at', '<', today())
                ->get()
                ->groupBy('outlet_id');
            foreach ($stale as $outletId => $tills) {
                $names = $tills->map(fn ($t) => trim(($t->openedBy?->first_name ?? '') . ' ' . ($t->openedBy?->last_name ?? ''))
                    ?: ($t->openedBy?->email ?? "till #{$t->id}"))->values()->all();
                NotificationService::tillsLeftOpen((int) $outletId, $tills->first()->outlet?->name ?? 'an outlet', $names);
            }
            $this->info('Morning lists: ' . $stale->count() . ' outlet(s)');
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * DEPLOY TO: app/Console/Commands/SendOverdueProductionNotifications.php
 *
 * REGISTER IN: app/Console/Kernel.php
 *   protected function schedule(Schedule $schedule): void
 *   {
 *       $schedule->command('notifications:overdue-production')->dailyAt('08:00');
 *       $schedule->command('logs:purge-old')->weekly();
 *   }
 *
 * RUN: php artisan notifications:overdue-production
 */
class SendOverdueProductionNotifications extends Command
{
    protected $signature   = 'notifications:overdue-production';
    protected $description = 'Send notifications for overdue production orders';

    public function handle(): int
    {
        $overdue = DB::table('production_orders as po')
            ->leftJoin('products as p', 'po.product_id', '=', 'p.id')
            ->leftJoin('product_translations as pt', function ($j) {
                $j->on('pt.product_id', '=', 'p.id')->where('pt.language_code', 'en');
            })
            ->where('po.due_date', '<', now()->toDateString())
            ->whereNotIn('po.status', ['completed', 'cancelled', 'draft'])
            ->select(
                'po.id',
                'po.order_number',
                'po.due_date',
                DB::raw("COALESCE(pt.name, p.sku, 'Unknown Product') as product_name")
            )
            ->get();

        if ($overdue->isEmpty()) {
            $this->info('No overdue production orders found.');
            return self::SUCCESS;
        }

        foreach ($overdue as $order) {
            // Get assigned user IDs for this production order
            $assigneeIds = DB::table('production_order_assignees')
                ->where('production_order_id', $order->id)
                ->pluck('user_id')
                ->toArray();

            NotificationService::productionOverdue(
                $order->id,
                $order->order_number,
                $order->product_name,
                $order->due_date,
                $assigneeIds
            );
        }

        $this->info("Sent overdue notifications for {$overdue->count()} production order(s).");
        return self::SUCCESS;
    }
}
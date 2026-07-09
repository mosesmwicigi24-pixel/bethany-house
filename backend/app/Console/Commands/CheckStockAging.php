<?php

namespace App\Console\Commands;

use App\Models\ProductSerial;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Flags tracked units that have sat unsold on the shelf beyond the aging window
 * (default 90 days) and notifies procurement + owners to physically verify them.
 * Scheduled daily. Manual run: php artisan serials:check-aging --days=90
 */
class CheckStockAging extends Command
{
    protected $signature = 'serials:check-aging {--days=90 : Days on the shelf before a unit is considered aged}';

    protected $description = 'Notify when tracked units have sat unsold on the shelf too long';

    public function handle(): int
    {
        $days   = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $count = ProductSerial::where('status', ProductSerial::IN_STOCK)
            ->where('stocked_at', '<', $cutoff)
            ->count();

        if ($count > 0) {
            NotificationService::stockAging($count, $days);
            $this->info("Notified: {$count} unit(s) aged past {$days} days.");
        } else {
            $this->info('No aged stock.');
        }

        return self::SUCCESS;
    }
}

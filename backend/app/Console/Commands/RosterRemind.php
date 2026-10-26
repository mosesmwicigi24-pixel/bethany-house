<?php

namespace App\Console\Commands;

use App\Models\Shift;
use App\Notifications\InAppNotification;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * roster:remind — an hour before a published shift starts, the person gets
 * one reminder (in-app, badge, push). Runs every 15 minutes; `reminded_at`
 * keeps it to one per shift.
 */
class RosterRemind extends Command
{
    protected $signature = 'roster:remind {--minutes=60 : How far ahead to remind} {--dry-run}';
    protected $description = 'Remind staff of a published shift starting within the window';

    public function handle(): int
    {
        $ahead = max(5, (int) $this->option('minutes'));
        $now = now();
        $sent = 0;

        $candidates = Shift::with(['user', 'outlet:id,name'])->published()->whereNotNull('user_id')->whereNull('reminded_at')
            ->whereBetween('date', [$now->copy()->subDay()->toDateString(), $now->copy()->addDay()->toDateString()])->get();

        foreach ($candidates as $shift) {
            $starts = $shift->startsAtCarbon();
            if ($starts->lt($now) || $starts->gt($now->copy()->addMinutes($ahead))) continue;
            $this->line("#{$shift->id} {$shift->user?->first_name} {$starts->format('D H:i')} at {$shift->outlet?->name}");
            if ($this->option('dry-run')) continue;

            $shift->forceFill(['reminded_at' => $now])->save();
            if ($shift->user && $shift->user->status === 'active') {
                NotificationService::deliver($shift->user, new InAppNotification(
                    title: "Shift at {$starts->format('H:i')}" . ($shift->label ? " · {$shift->label}" : ''),
                    body:  "Your shift at {$shift->outlet?->name} starts in " . max(1, (int) $now->diffInMinutes($starts)) . " minutes. Clock in when you arrive.",
                    actionUrl: '/me/clock',
                    icon: 'calendar',
                    data: ['shift_id' => $shift->id],
                ));
                $sent++;
            }
        }

        $this->info($this->option('dry-run') ? 'Dry run.' : "Reminded {$sent}.");
        return self::SUCCESS;
    }
}

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

        // C1 nudges: not clocked in 5 min after the shift starts; still clocked in after it ends.
        $rules = \App\Support\AttendanceRules::all();
        $nudged = 0;
        $todays = Shift::with(['user', 'outlet:id,name'])->published()->whereNotNull('user_id')->where('date', $now->toDateString())->get();
        foreach ($todays as $shift) {
            if (!$shift->user || $shift->user->status !== 'active') continue;
            $starts = $shift->startsAtCarbon(); $ends = $shift->endsAtCarbon();
            $active = \App\Models\TimeEntry::where('user_id', $shift->user_id)->active()->exists();
            if ($rules['remind_clock_in'] && !$shift->clock_in_nudged_at && $now->gte($starts->copy()->addMinutes(5)) && $now->lt($starts->copy()->addMinutes(90)) && !$active
                && !\App\Models\TimeEntry::where('user_id', $shift->user_id)->whereDate('clock_in_at', $now->toDateString())->exists()) {
                $this->line("nudge in: #{$shift->id} {$shift->user->first_name}");
                if ($this->option('dry-run')) continue;
                $shift->forceFill(['clock_in_nudged_at' => $now])->save();
                NotificationService::deliver($shift->user, new InAppNotification(title: 'Not clocked in yet', body: "Your shift at {$shift->outlet?->name} started at {$starts->format('H:i')}. Clock in when you arrive.", actionUrl: '/me/clock', icon: 'clock', data: ['shift_id' => $shift->id]));
                $nudged++;
            }
            if ($rules['remind_clock_out'] && !$shift->clock_out_nudged_at && $now->gte($ends) && $now->lt($ends->copy()->addMinutes(90)) && $active) {
                $this->line("nudge out: #{$shift->id} {$shift->user->first_name}");
                if ($this->option('dry-run')) continue;
                $shift->forceFill(['clock_out_nudged_at' => $now])->save();
                NotificationService::deliver($shift->user, new InAppNotification(title: 'Still clocked in', body: "Your shift ended at {$ends->format('H:i')}. Clock out if you have finished.", actionUrl: '/me/clock', icon: 'clock', data: ['shift_id' => $shift->id]));
                $nudged++;
            }
        }

        // C2 automation: no punch N minutes after a published shift started → the manager, once per shift.
        $missed = 0;
        if ($auto = \App\Support\Automations::active('missed_shift')) {
            foreach ($todays as $shift) {
                if (!$shift->user || $shift->user->status !== 'active' || $shift->missed_alerted_at) continue;
                $starts = $shift->startsAtCarbon();
                if ($now->lt($starts->copy()->addMinutes((int) $auto['threshold'])) || $now->gte($shift->endsAtCarbon())) continue;
                if (\App\Models\TimeEntry::where('user_id', $shift->user_id)->whereDate('clock_in_at', $now->toDateString())->exists()) continue;
                $this->line("missed: #{$shift->id} {$shift->user->first_name}");
                if ($this->option('dry-run')) continue;
                $shift->forceFill(['missed_alerted_at' => $now])->save();
                $name = trim("{$shift->user->first_name} {$shift->user->last_name}");
                NotificationService::deliver(\App\Support\Automations::recipientsFor('missed_shift', $shift->user), new InAppNotification(
                    title: "{$name} has not clocked in",
                    body:  "Shift at {$shift->outlet?->name} started at {$starts->format('H:i')}; no punch {$auto['threshold']} minutes on.",
                    actionUrl: '/people/overview', icon: 'clock', data: ['shift_id' => $shift->id, 'automation' => 'missed_shift']));
                $missed++;
            }
        }

        $this->info($this->option('dry-run') ? 'Dry run.' : "Reminded {$sent}, nudged {$nudged}, missed {$missed}.");
        return self::SUCCESS;
    }
}

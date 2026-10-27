<?php

namespace App\Console\Commands;

use App\Models\Todo;
use App\Notifications\InAppNotification;
use App\Services\NotificationService;
use App\Support\Automations;
use Illuminate\Console\Command;

/**
 * todos:remind — every five minutes, when the `todo_due` automation is on:
 * an open to-do due within the threshold reminds its person once
 * (`reminded_at`); one past its due time tells whoever gave it, once
 * (`overdue_told_at`). Nothing goes to an inactive person, and nobody is
 * told about their own to-do twice.
 */
class TodosRemind extends Command
{
    protected $signature = 'todos:remind {--dry-run : List, send nothing}';
    protected $description = 'Remind people of to-dos about to fall due and tell givers of overdue ones';

    public function handle(): int
    {
        $auto = Automations::active('todo_due');
        if (!$auto) { $this->info('To-do reminders are off.'); return self::SUCCESS; }
        $now = now(); $soon = $now->copy()->addMinutes((int) $auto['threshold']); $n = 0;

        $due = Todo::with('user')->where('status', 'open')->whereNull('reminded_at')->whereNotNull('due_at')->where('due_at', '<=', $soon)->where('due_at', '>', $now)->get();
        foreach ($due as $t) {
            if ($t->user?->status !== 'active') continue;
            $this->line("remind: {$t->user->first_name} — {$t->title} (due {$t->due_at->format('D H:i')})"); $n++;
            if ($this->option('dry-run')) continue;
            $t->update(['reminded_at' => $now]);
            NotificationService::deliver($t->user, new InAppNotification(title: "Due {$t->due_at->diffForHumans($now, ['syntax' => \Carbon\CarbonInterface::DIFF_RELATIVE_TO_NOW])}: {$t->title}", body: 'By ' . $t->due_at->format('H:i') . '.', actionUrl: '/todos', icon: 'clipboard', data: ['todo_id' => $t->id]));
        }

        $late = Todo::with(['user', 'creator'])->where('status', 'open')->whereNull('overdue_told_at')->whereNotNull('due_at')->where('due_at', '<', $now)->whereColumn('created_by', '!=', 'user_id')->get();
        foreach ($late as $t) {
            if ($t->creator?->status !== 'active') continue;
            $this->line("overdue: {$t->creator->first_name} hears — {$t->user?->first_name}: {$t->title}"); $n++;
            if ($this->option('dry-run')) continue;
            $t->update(['overdue_told_at' => $now]);
            NotificationService::deliver($t->creator, new InAppNotification(title: "Overdue: {$t->title}", body: "{$t->user?->first_name} has not done it (was due " . $t->due_at->format('D j M H:i') . ').', actionUrl: '/todos?scope=given', icon: 'clipboard', data: ['todo_id' => $t->id]));
        }
        $this->info(($this->option('dry-run') ? 'Would send ' : 'Sent ') . "{$n} nudge(s).");
        return self::SUCCESS;
    }
}

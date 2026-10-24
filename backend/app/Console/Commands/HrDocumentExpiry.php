<?php

namespace App\Console\Commands;

use App\Models\StaffDocument;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * hr:document-expiry — once a day, tell the people who keep employee files
 * which staff documents expire within the window (default 30 days) or have
 * already expired. One notice per run per holder, listing the documents;
 * nothing when there are none.
 */
class HrDocumentExpiry extends Command
{
    protected $signature = 'hr:document-expiry {--days=30 : Window in days} {--dry-run : Print, send nothing}';
    protected $description = 'Notify hr.edit holders of staff documents expiring within the window';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $docs = StaffDocument::with('user:id,first_name,last_name')
            ->whereNull('removed_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays($days)->toDateString())
            ->orderBy('expires_at')
            ->get();

        if ($docs->isEmpty()) {
            $this->info('No staff documents expiring.');
            return self::SUCCESS;
        }

        foreach ($docs as $d) {
            $this->line(sprintf('%s · %s · %s · expires %s', $d->user?->first_name . ' ' . $d->user?->last_name, $d->kind, $d->title, $d->expires_at->toDateString()));
        }
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $holders = User::where('user_type', 'staff')->where('status', 'active')->get()
            ->filter(fn (User $u) => $u->can('hr.edit'))->values();
        if ($holders->isEmpty()) {
            $this->warn('Nobody holds hr.edit; nothing sent.');
            return self::SUCCESS;
        }

        $expired = $docs->filter(fn ($d) => $d->expires_at->lt(now()->startOfDay()))->count();
        $first   = $docs->first();
        $body    = $docs->take(5)->map(fn ($d) => trim("{$d->user?->first_name} {$d->user?->last_name}") . ": {$d->title} ({$d->expires_at->format('j M')})")->implode('; ')
            . ($docs->count() > 5 ? '; …' : '');

        Notification::send($holders, new InAppNotification(
            title:     $docs->count() . ' staff document' . ($docs->count() === 1 ? '' : 's') . ' expiring' . ($expired ? " ({$expired} already expired)" : ''),
            body:      $body,
            actionUrl: '/people/' . $first->user_id,
            icon:      'people',
            data:      ['count' => $docs->count(), 'expired' => $expired, 'days' => $days],
        ));

        $this->info("Notified {$holders->count()} file keeper(s) about {$docs->count()} document(s).");
        return self::SUCCESS;
    }
}

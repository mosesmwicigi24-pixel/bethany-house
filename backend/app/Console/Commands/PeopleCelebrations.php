<?php

namespace App\Console\Commands;

use App\Models\EmployeeProfile;
use App\Models\Post;
use App\Support\Automations;
use Illuminate\Console\Command;

/**
 * people:celebrations — each morning, a birthday or work-anniversary post
 * on the Updates feed for anyone whose employee file says so today. One
 * per person per year (posts.celebration_key), so a re-run posts nothing
 * twice. Switched off on Setup › Automations (key celebrations). A 29
 * February birthday is posted on the 28th in other years.
 */
class PeopleCelebrations extends Command
{
    protected $signature = 'people:celebrations {--date= : Run as if today were this date} {--dry-run : List, post nothing}';
    protected $description = 'Post birthdays and work anniversaries on the Updates feed';

    public function handle(): int
    {
        if (!Automations::active('celebrations')) { $this->info('Celebrations are off.'); return self::SUCCESS; }
        $today = $this->option('date') ? \Carbon\Carbon::parse($this->option('date')) : today();
        $md = $today->format('m-d');
        $leapDay = $md === '02-28' && !$today->isLeapYear();
        $posted = 0;
        // Active staff only (user_type is a cast attribute: filter in SQL, not by string comparison).
        $profiles = EmployeeProfile::with('user')->whereHas('user', fn ($q) => $q->where('status', 'active')->where('user_type', 'staff'))
            ->where(fn ($q) => $q->whereNotNull('date_of_birth')->orWhereNotNull('hire_date'))->get();
        foreach ($profiles as $p) {
            $u = $p->user;
            if (!$u || ($p->end_date && $p->end_date->lt($today))) continue;
            $first = $u->first_name;
            $name = trim("{$u->first_name} {$u->last_name}");
            if ($p->date_of_birth && ($p->date_of_birth->format('m-d') === $md || ($leapDay && $p->date_of_birth->format('m-d') === '02-29'))) {
                $this->post("birthday:{$u->id}:{$today->year}", $u->id, "Happy birthday, {$first}! 🎂", "Wish {$first} a good one today.", $posted);
            }
            if ($p->hire_date && $p->hire_date->format('m-d') === $md && $p->hire_date->year < $today->year) {
                $years = $today->year - $p->hire_date->year;
                $this->post("anniversary:{$u->id}:{$today->year}", $u->id, "{$years} year" . ($years === 1 ? '' : 's') . " at Bethany House 🎉", "{$name} joined on " . $p->hire_date->format('j F Y') . ". Thank you for {$years} year" . ($years === 1 ? '' : 's') . ".", $posted);
            }
        }
        $this->info(($this->option('dry-run') ? 'Would post ' : 'Posted ') . "{$posted} celebration(s) for {$today->toDateString()}.");
        return self::SUCCESS;
    }

    private function post(string $key, int $userId, string $title, string $body, int &$posted): void
    {
        if (Post::withTrashed()->where('celebration_key', $key)->exists()) return;
        $this->line("{$key}: {$title}");
        $posted++;
        if ($this->option('dry-run')) return;
        Post::create(['kind' => 'celebration', 'recipient_id' => $userId, 'title' => $title, 'body' => $body, 'celebration_key' => $key, 'published_at' => now(), 'notified_at' => now(), 'comments_enabled' => true]);
    }
}

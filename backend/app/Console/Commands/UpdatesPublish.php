<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\UpdatesController;
use App\Models\Post;
use Illuminate\Console\Command;

/** updates:publish — scheduled posts whose time has come go out (every five minutes). */
class UpdatesPublish extends Command
{
    protected $signature = 'updates:publish {--dry-run : List, publish nothing}';
    protected $description = 'Publish scheduled Updates posts that are due';

    public function handle(): int
    {
        $due = Post::whereNull('published_at')->whereNotNull('publish_at')->where('publish_at', '<=', now())->orderBy('publish_at')->get();
        foreach ($due as $post) {
            $this->line("#{$post->id} {$post->kind}: {$post->title}");
            if ($this->option('dry-run')) continue;
            $post->forceFill(['published_at' => now()])->save();
            UpdatesController::notify($post);
        }
        $this->info(($this->option('dry-run') ? 'Would publish ' : 'Published ') . $due->count() . ' post(s).');
        return self::SUCCESS;
    }
}

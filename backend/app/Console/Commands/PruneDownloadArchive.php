<?php

namespace App\Console\Commands;

use App\Models\DownloadRequest;
use App\Services\ActivityLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Weekly: delete server copies of downloaded files older than
 * audit.downloads.archive_retention_days (90). The download's row — who, what,
 * when, the fingerprint — stays forever; only the bytes go. The owner's
 * emailed copy is the long-term one.
 */
class PruneDownloadArchive extends Command
{
    protected $signature   = 'downloads:prune-archive';
    protected $description = 'Delete archived copies of downloads past retention (records are kept)';

    public function handle(): int
    {
        $days = max(7, (int) config('audit.downloads.archive_retention_days', 90));
        $disk = Storage::disk(config('audit.downloads.archive_disk', 'local'));
        $n = 0;

        DownloadRequest::whereNotNull('archive_path')
            ->where('downloaded_at', '<', now()->subDays($days))
            ->chunkById(200, function ($rows) use ($disk, &$n) {
                foreach ($rows as $dr) {
                    $disk->delete($dr->archive_path);
                    $dr->forceFill(['archive_path' => null])->save();
                    $n++;
                }
            });

        ActivityLogService::log('download_archive_pruned', null, ['files' => $n, 'retention_days' => $days],
            "Pruned {$n} archived download copies older than {$days} days (records kept)");
        $this->info("Pruned {$n} archived copies older than {$days} days.");

        return self::SUCCESS;
    }
}

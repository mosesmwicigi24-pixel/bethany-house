<?php

namespace App\Jobs;

use App\Mail\DownloadCopyMail;
use App\Models\DownloadRequest;
use App\Services\ActivityLogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * The owner's silent copy of a download (owner decision 2026-09-21): who took
 * what, when, from where, why, approved by whom — and the file itself when it
 * is small enough to attach and is not a database backup.
 *
 * Silent means the person downloading is never told. It is sent once
 * (owner_notified_at), retried on mail failure, and a copy that finally
 * cannot be delivered is written to the audit trail so the daily digest and
 * the Activity Log both show it.
 */
class SendDownloadCopyToOwner implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 5;
    public int $timeout = 120;

    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function __construct(public readonly int $downloadRequestId) {}

    public function handle(): void
    {
        $dr = DownloadRequest::with(['user', 'decider'])->find($this->downloadRequestId);
        if (!$dr || $dr->owner_notified_at) {
            return;   // gone, or already sent
        }

        $to = (string) config('audit.owner_email');
        if ($to === '') {
            return;
        }

        Mail::to($to)->send(new DownloadCopyMail($dr));

        $dr->forceFill(['owner_notified_at' => now()])->save();
    }

    public function failed(\Throwable $e): void
    {
        ActivityLogService::log('download_owner_copy_failed', DownloadRequest::find($this->downloadRequestId), [
            'error' => mb_substr($e->getMessage(), 0, 500),
        ], "The owner's copy of download #{$this->downloadRequestId} could not be emailed");
    }
}

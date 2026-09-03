<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\ProductVideoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Turns a parked upload into the storefront's clip.
 *
 * This work used to happen inside the upload request. ffmpeg was given up to
 * config('video.timeout') — 240 seconds — while holding a php-fpm worker, and
 * the browser gave up at around thirty. So the owner saw a failure, uploaded
 * again, and each attempt pinned another worker; enough of them and the hub
 * stops answering anything at all. Encoding a twelve-second 720p clip is
 * queue work, not request work.
 *
 * $timeout sits above the ffmpeg budget and BELOW the queue's retry_after
 * (360), so Redis never hands this job to a second worker while the first is
 * still encoding — that would produce two files and two updates for one
 * upload.
 *
 * Two tries, not the worker's three: a clip ffmpeg cannot read is not going to
 * become readable, and each attempt costs the full budget. One retry covers
 * the genuinely transient case (a worker restarted mid-encode).
 */
class ConvertProductVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 300;

    public function __construct(
        private readonly int    $productId,
        private readonly string $stashPath,
        private readonly string $stashDisk = 'local',
    ) {}

    public function handle(ProductVideoService $service): void
    {
        $product = Product::find($this->productId);
        if (! $product) {
            $this->discardStash();

            return;                     // deleted while it queued; nothing to attach to
        }

        $previous = $product->video_url;

        $result = $service->convertStashed($this->stashPath, "products/{$product->id}", $this->stashDisk);

        $product->forceFill([
            'video_url'    => $result['url'],
            'video_status' => null,     // settled: there is a playable clip
        ])->save();

        // Only once the replacement is safely stored and pointed at.
        if ($previous && $previous !== $result['url']) {
            $service->delete($previous);
        }

        $this->discardStash();
    }

    /**
     * Both tries are gone. Say so on the product rather than leaving it
     * 'processing' forever — a spinner that never stops is worse than a
     * failure, because nobody knows to try again.
     */
    public function failed(?\Throwable $e): void
    {
        Log::error('product video conversion failed', [
            'product_id' => $this->productId,
            'stash'      => $this->stashPath,
            'error'      => $e?->getMessage(),
        ]);

        Product::where('id', $this->productId)->update(['video_status' => 'failed']);

        $this->discardStash();
    }

    private function discardStash(): void
    {
        try {
            Storage::disk($this->stashDisk)->delete($this->stashPath);
        } catch (\Throwable $e) {
            Log::warning('could not remove stashed video', [
                'stash' => $this->stashPath, 'error' => $e->getMessage(),
            ]);
        }
    }
}

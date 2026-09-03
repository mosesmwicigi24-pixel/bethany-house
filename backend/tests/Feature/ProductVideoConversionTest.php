<?php

namespace Tests\Feature;

use App\Jobs\ConvertProductVideo;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductVideoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Encoding a video is queue work, not request work.
 *
 * ffmpeg ran inside the upload request with a 240-second budget, holding a
 * php-fpm worker the whole time. The browser gives up around thirty seconds, so
 * the owner saw a failure and uploaded again — and each attempt pinned another
 * worker. Enough of them and the hub stops answering anything at all.
 */
class ProductVideoConversionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(\Spatie\Permission\Models\Role::findOrCreate('super_admin', 'sanctum'));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        \Laravel\Sanctum\Sanctum::actingAs($user);

        return $user;
    }

    private function clip(string $name = 'clip.mp4'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 512, 'video/mp4');
    }

    public function test_the_request_returns_immediately_and_queues_the_work(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->admin();
        $product = Product::factory()->create();

        $this->postJson("/api/v1/admin/products/{$product->id}/video", ['video' => $this->clip()])
            ->assertStatus(202)
            ->assertJsonPath('video_status', 'processing');

        Queue::assertPushed(ConvertProductVideo::class);
    }

    public function test_the_upload_request_never_runs_ffmpeg(): void
    {
        // The point of the change: the web request does no encoding at all.
        Queue::fake();
        Storage::fake('local');
        $this->admin();
        $product = Product::factory()->create();

        $service = \Mockery::mock(ProductVideoService::class)->makePartial();
        $service->shouldNotReceive('convertStashed');
        $service->shouldNotReceive('process');
        $this->app->instance(ProductVideoService::class, $service);

        $this->postJson("/api/v1/admin/products/{$product->id}/video", ['video' => $this->clip()])
            ->assertStatus(202);
    }

    public function test_the_product_says_it_is_converting(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->admin();
        $product = Product::factory()->create();

        $this->postJson("/api/v1/admin/products/{$product->id}/video", ['video' => $this->clip()]);

        $this->assertSame('processing', $product->fresh()->video_status,
            'without this the screen cannot tell "no video" from "converting"');
    }

    public function test_the_previous_clip_stays_live_while_the_new_one_converts(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->admin();
        $product = Product::factory()->create(['video_url' => 'https://x/storage/products/1/old.mp4']);

        $this->postJson("/api/v1/admin/products/{$product->id}/video", ['video' => $this->clip()]);

        $this->assertSame('https://x/storage/products/1/old.mp4', $product->fresh()->video_url,
            'a conversion that fails must leave the product exactly as it was');
    }

    public function test_an_unsupported_file_is_refused_by_the_request_not_the_queue(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->admin();
        $product = Product::factory()->create();

        $this->postJson("/api/v1/admin/products/{$product->id}/video", [
            'video' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ])->assertStatus(422);

        Queue::assertNothingPushed();
        $this->assertNull($product->fresh()->video_status);
    }

    public function test_a_failed_conversion_stops_saying_processing(): void
    {
        // A spinner that never stops is worse than a failure — nobody knows to
        // try again.
        Storage::fake('local');
        $this->admin();
        $product = Product::factory()->create(['video_status' => 'processing']);

        (new ConvertProductVideo($product->id, 'product-videos/incoming/gone.mp4'))
            ->failed(new \RuntimeException('ffmpeg failed'));

        $this->assertSame('failed', $product->fresh()->video_status);
    }

    public function test_removing_the_video_clears_a_stuck_state(): void
    {
        $this->admin();
        $product = Product::factory()->create(['video_status' => 'failed']);

        $this->deleteJson("/api/v1/admin/products/{$product->id}/video")->assertOk();

        $this->assertNull($product->fresh()->video_status);
        $this->assertNull($product->fresh()->video_url);
    }

    /**
     * A container can name other inputs — an HLS playlist or the concat
     * demuxer will have ffmpeg open http:// or file:// on the uploader's
     * behalf, which turns the upload box into a reader of this server's disk
     * and network.
     */
    public function test_ffmpeg_may_only_open_local_files(): void
    {
        $cmd = app(ProductVideoService::class)->ffmpegCommand('ffmpeg', '/tmp/in.mov', '/tmp/out.mp4');

        $i = array_search('-protocol_whitelist', $cmd, true);

        $this->assertNotFalse($i, 'untrusted input must not reach ffmpeg with every protocol enabled');
        $this->assertSame('file', $cmd[$i + 1]);
        $this->assertLessThan(array_search('-i', $cmd, true), $i, 'the whitelist must precede the input');
    }

    /**
     * Redis hands a job to a second worker once retry_after passes. Every job
     * timeout must sit below it, or one upload becomes two encodes and two
     * updates. SendEodReportEmail (120s) was already over the old 90.
     */
    public function test_no_job_can_outlive_the_queues_retry_window(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');

        foreach ([ConvertProductVideo::class, \App\Jobs\SendEodReportEmail::class] as $job) {
            $timeout = (new \ReflectionClass($job))->getDefaultProperties()['timeout'] ?? 60;
            $this->assertLessThan($retryAfter, $timeout, "{$job} can be run twice for one dispatch");
        }
    }
}

<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * ProductVideoService
 *
 * Stores the one short clip a product can carry (products.video_url) the way
 * ImageService stores photos: on the public disk under products/{id}/, with
 * an absolute URL baked in. Unlike photos there is no GD pipeline — the
 * heavy lifting is ffmpeg (see config/video.php), which turns a raw phone
 * recording into the small, silent, H.264 loop the storefront's hover-to-play
 * cards want. If ffmpeg is missing, browser-native formats (MP4, WebM) are
 * stored untouched and everything else is refused with a clear message.
 */
class ProductVideoService
{
    /** Uploads accepted. Extension-based, like chat attachments: phone
     *  .mov uploads are routinely mislabelled by finfo. */
    public const ALLOWED_EXT = ['mp4', 'mov', 'm4v', 'webm'];

    /** Formats every current browser plays as uploaded (H.264 MP4 / VP9 WebM
     *  from a normal export). Stored as-is when ffmpeg is unavailable. */
    private const WEB_READY_EXT = ['mp4', 'webm'];

    /**
     * Convert + store an uploaded clip.
     *
     * Returns: path, url, converted (bool), size (bytes).
     *
     * @throws ValidationException on an unsupported file or a failed conversion
     */
    public function process(UploadedFile $file, string $directory, string $disk = 'public'): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, self::ALLOWED_EXT, true)) {
            throw ValidationException::withMessages([
                'video' => 'Upload an MP4, MOV, M4V or WebM clip.',
            ]);
        }

        $name = (string) Str::uuid();
        $ffmpeg = $this->ffmpegBinary();

        if ($ffmpeg === null) {
            if (! in_array($ext, self::WEB_READY_EXT, true)) {
                throw ValidationException::withMessages([
                    'video' => "This server cannot convert .{$ext} clips. Export it as an MP4 (H.264) and upload again.",
                ]);
            }
            $path = "{$directory}/{$name}.{$ext}";
            Storage::disk($disk)->putFileAs($directory, $file, "{$name}.{$ext}");

            return [
                'path' => $path,
                'url' => $this->toUrl($path, $disk),
                'converted' => false,
                'size' => Storage::disk($disk)->size($path),
            ];
        }

        $tmp = rtrim(sys_get_temp_dir(), '/')."/bh-video-{$name}.mp4";
        try {
            $process = new Process($this->ffmpegCommand($ffmpeg, $file->getRealPath(), $tmp));
            $process->setTimeout((int) config('video.timeout', 240));
            $process->run();

            if (! $process->isSuccessful() || ! is_file($tmp) || filesize($tmp) === 0) {
                report(new \RuntimeException('ffmpeg failed: '.trim($process->getErrorOutput())));
                throw ValidationException::withMessages([
                    'video' => 'The clip could not be converted. Export it as an MP4 (H.264) and upload again.',
                ]);
            }

            $path = "{$directory}/{$name}.mp4";
            Storage::disk($disk)->put($path, file_get_contents($tmp));
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }

        return [
            'path' => $path,
            'url' => $this->toUrl($path, $disk),
            'converted' => true,
            'size' => Storage::disk($disk)->size($path),
        ];
    }

    /** Remove a stored clip by its URL (or storage path). Missing files are ignored. */
    public function delete(?string $urlOrPath, string $disk = 'public'): void
    {
        if (! $urlOrPath) {
            return;
        }
        $path = $this->toStoragePath($urlOrPath);
        if ($path && Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
        }
    }

    /** Resolved ffmpeg binary, or null when conversion is unavailable. */
    public function ffmpegBinary(): ?string
    {
        $bin = trim((string) config('video.ffmpeg', ''));
        if ($bin === '') {
            return null;
        }
        if (str_contains($bin, '/')) {
            return is_executable($bin) ? $bin : null;
        }

        return (new ExecutableFinder)->find($bin) ?: null;
    }

    /**
     * The conversion the storefront's clips are specified around
     * (bethanywebsite docs/PRODUCT_VIDEOS.md): H.264 Main, 720 px on the
     * long side, 30 fps, no audio, first N seconds, faststart.
     *
     * @return string[]
     */
    public function ffmpegCommand(string $ffmpeg, string $input, string $output): array
    {
        $px = max(240, (int) config('video.max_px', 720));
        $sec = max(1, (int) config('video.max_seconds', 12));

        return [
            $ffmpeg, '-y', '-v', 'error', '-nostdin',
            '-i', $input,
            '-t', (string) $sec,
            '-vf', "scale='if(gt(iw,ih),{$px},-2)':'if(gt(iw,ih),-2,{$px})',fps=30,setsar=1,format=yuv420p",
            '-c:v', 'libx264', '-profile:v', 'main', '-level', '3.1',
            '-preset', 'medium', '-crf', '26',
            '-movflags', '+faststart',
            '-an',
            $output,
        ];
    }

    private function toUrl(string $path, string $disk): string
    {
        if ($disk === 'public') {
            return config('app.url').'/storage/'.$path;
        }

        return Storage::disk($disk)->url($path);
    }

    private function toStoragePath(string $urlOrPath): ?string
    {
        if (str_starts_with($urlOrPath, 'http')) {
            $parsed = parse_url($urlOrPath, PHP_URL_PATH);
            if (! $parsed) {
                return null;
            }

            return ltrim(preg_replace('#^/storage/#', '', $parsed), '/');
        }

        return ltrim($urlOrPath, '/');
    }
}

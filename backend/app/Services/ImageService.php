<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * ImageService
 *
 * Server-side image compression and format conversion for all public-facing
 * image uploads (products, categories, logos, avatars).
 *
 * Output format: WebP (with JPEG fallback if WebP not available in GD).
 * Requires: php-gd (php8.3-gd) — already installed per ci.yml.
 */
class ImageService
{
    private const QUALITY_WEBP = 82;
    private const QUALITY_JPEG = 85;

    private const MAX_DIMENSIONS = [
        'product'   => ['w' => 1600, 'h' => 1600],
        'category'  => ['w' => 1200, 'h' => 800],
        'logo'      => ['w' => 800,  'h' => 400],
        'avatar'    => ['w' => 400,  'h' => 400],
        'thumbnail' => ['w' => 400,  'h' => 400],
    ];

    /** Path to the watermark PNG, relative to the storage_path() root. */
    private const WATERMARK_PATH = 'app/watermark.png';

    /**
     * Watermark width as a fraction of the host image's width. Kept
     * deliberately small (12%) so it reads as a subtle brand mark in the
     * center of the photo rather than something that competes with or
     * obscures the product itself. The watermark's own aspect ratio is
     * preserved when scaling - it is not stretched to a fixed box.
     */
    private const WATERMARK_SCALE = 0.12;

    /**
     * Watermark opacity, 0 (invisible) to 100 (fully opaque). Low value
     * keeps it faint - visible enough to discourage casual image theft,
     * never strong enough to hide product detail underneath it.
     */
    private const WATERMARK_OPACITY = 18;

    /** Cached watermark GD resource - loaded once per request, not once per image. */
    private static mixed $watermarkCache = null;
    private static bool $watermarkLoadAttempted = false;

    /** Cached WebP support flag */
    private static ?bool $webpSupported = null;

    /**
     * Process and store a public image with compression.
     *
     * Returns: path, url, thumbnail_path, thumbnail_url, original_size, compressed_size
     */
    public function process(
        UploadedFile $file,
        string $directory,
        string $type = 'product',
        string $disk = 'public',
    ): array {
        // SVG: store as-is — GD cannot read vector files
        if (in_array($file->getMimeType(), ['image/svg+xml', 'image/svg'])
            || strtolower($file->getClientOriginalExtension()) === 'svg') {
            $path = $file->store($directory, $disk);
            $url  = $this->toUrl($path, $disk);
            return [
                'path'             => $path,
                'url'              => $url,
                'thumbnail_path'   => null,
                'thumbnail_url'    => null,
                'original_size'    => $file->getSize(),
                'compressed_size'  => $file->getSize(),
            ];
        }

        $originalSize = $file->getSize();

        // Load image into GD
        $gdImage = $this->loadGdImage($file);

        // GD failed — fall back to storing original file unchanged
        if ($gdImage === false || $gdImage === null) {
            $path = $file->store($directory, $disk);
            $url  = $this->toUrl($path, $disk);
            return [
                'path'             => $path,
                'url'              => $url,
                'thumbnail_path'   => null,
                'thumbnail_url'    => null,
                'original_size'    => $originalSize,
                'compressed_size'  => $originalSize,
            ];
        }

        $dims    = self::MAX_DIMENSIONS[$type] ?? self::MAX_DIMENSIONS['product'];
        $resized = $this->resizeDown($gdImage, $dims['w'], $dims['h']);

        // Snapshot the resized image BEFORE watermarking, for the thumbnail
        // branch below. Thumbnails stay clean even for product images -
        // a watermark at 400×400 would be visually noisy and out of
        // proportion, so the full-size copy gets it but the thumbnail never does.
        $preWatermark = null;
        if (in_array($type, ['product', 'category'])) {
            $preWatermark = imagecreatetruecolor(imagesx($resized), imagesy($resized));
            imagealphablending($preWatermark, false);
            imagesavealpha($preWatermark, true);
            imagecopy($preWatermark, $resized, 0, 0, 0, 0, imagesx($resized), imagesy($resized));
        }

        // Product images only - applied to the full-size resized image,
        // after resizing and before encoding.
        if ($type === 'product') {
            $this->applyWatermark($resized);
        }

        $useWebP    = $this->isWebPSupported();
        $ext        = $useWebP ? 'webp' : 'jpg';
        $filename   = Str::uuid() . '.' . $ext;
        $fullPath   = $directory . '/' . $filename;

        $encoded = $this->encode($resized, $useWebP, self::QUALITY_WEBP);
        Storage::disk($disk)->put($fullPath, $encoded);
        $compressedSize = strlen($encoded);
        $url            = $this->toUrl($fullPath, $disk);

        // Thumbnail for product and category types — generated from
        // $preWatermark, not $resized, so it never carries the watermark.
        $thumbnailPath = null;
        $thumbnailUrl  = null;

        if ($preWatermark !== null) {
            $tDims       = self::MAX_DIMENSIONS['thumbnail'];
            $thumb       = $this->resizeDown($preWatermark, $tDims['w'], $tDims['h']);
            $thumbFile   = Str::uuid() . '_thumb.' . $ext;
            $thumbPath   = $directory . '/' . $thumbFile;
            $thumbQuality = $useWebP ? 75 : 80;
            Storage::disk($disk)->put($thumbPath, $this->encode($thumb, $useWebP, $thumbQuality));
            $thumbnailPath = $thumbPath;
            $thumbnailUrl  = $this->toUrl($thumbPath, $disk);
            $this->destroyGd($thumb, $preWatermark);
        }

        $this->destroyGd($resized, $gdImage);

        return [
            'path'            => $fullPath,
            'url'             => $url,
            'thumbnail_path'  => $thumbnailPath,
            'thumbnail_url'   => $thumbnailUrl,
            'original_size'   => $originalSize,
            'compressed_size' => $compressedSize,
            'full_path'       => $fullPath,
        ];
    }

    /**
     * Delete a stored image (and its thumbnail) by URL or storage path.
     */
    public function delete(string $urlOrPath, string $disk = 'public'): void
    {
        $path = $this->toStoragePath($urlOrPath);
        if ($path && Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
        }

        // Auto-delete thumbnail: foo.webp → foo_thumb.webp
        if ($path) {
            $thumbPath = preg_replace('/(\.(webp|jpg|jpeg|png))$/i', '_thumb$1', $path);
            if ($thumbPath !== $path && Storage::disk($disk)->exists($thumbPath)) {
                Storage::disk($disk)->delete($thumbPath);
            }
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function loadGdImage(UploadedFile $file): mixed
    {
        $tmpPath = $file->getRealPath();
        $mime    = $file->getMimeType();

        return match (true) {
            str_contains($mime, 'jpeg') || str_contains($mime, 'jpg') => @imagecreatefromjpeg($tmpPath),
            str_contains($mime, 'png')  => @imagecreatefrompng($tmpPath),
            str_contains($mime, 'webp') => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : null,
            str_contains($mime, 'gif')  => @imagecreatefromgif($tmpPath),
            default                     => null,
        };
    }

    private function resizeDown(mixed $src, int $maxW, int $maxH): mixed
    {
        $srcW = imagesx($src);
        $srcH = imagesy($src);

        if ($srcW <= $maxW && $srcH <= $maxH) {
            return $src; // Already within bounds
        }

        $ratio = min($maxW / $srcW, $maxH / $srcH);
        $dstW  = (int) round($srcW * $ratio);
        $dstH  = (int) round($srcH * $ratio);

        $dst = imagecreatetruecolor($dstW, $dstH);

        // White background (handles PNG transparency gracefully for JPEG output)
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white);

        // Preserve transparency for WebP output
        imagealphablending($dst, false);
        imagesavealpha($dst, true);

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

        return $dst;
    }

    /**
     * Composites the brand watermark onto the center of $target in place.
     * $target is a truecolor GD resource (already resized) - modified
     * directly, nothing is returned.
     *
     * Uses a manual per-pixel alpha blend rather than GD's built-in
     * imagecopymerge(), because imagecopymerge() does not correctly respect
     * the source PNG's own per-pixel alpha channel - it would either ignore
     * transparency entirely (treating the logomark's transparent background
     * as solid white/black) or double-apply opacity in inconsistent ways
     * depending on the GD build. Resampling the watermark onto a true RGBA
     * canvas first and blending channel-by-channel guarantees the result
     * looks identical regardless of the server's specific GD version.
     */
    private function applyWatermark(mixed $target): void
    {
        $mark = $this->loadWatermark();
        if (!$mark) {
            return; // Missing/unreadable watermark file - skip silently, never block an upload over this.
        }

        $targetW = imagesx($target);
        $targetH = imagesy($target);
        $markW   = imagesx($mark);
        $markH   = imagesy($mark);

        // Scale the watermark to a fraction of the target's width, preserving
        // its own aspect ratio - never stretched into a fixed box.
        $scale    = ($targetW * self::WATERMARK_SCALE) / $markW;
        $scaledW  = max(1, (int) round($markW * $scale));
        $scaledH  = max(1, (int) round($markH * $scale));

        $scaledMark = imagecreatetruecolor($scaledW, $scaledH);
        imagealphablending($scaledMark, false);
        imagesavealpha($scaledMark, true);
        // Transparent canvas to resample onto, so the logomark's own
        // transparent background stays transparent after resizing.
        $transparent = imagecolorallocatealpha($scaledMark, 0, 0, 0, 127);
        imagefill($scaledMark, 0, 0, $transparent);
        imagecopyresampled($scaledMark, $mark, 0, 0, 0, 0, $scaledW, $scaledH, $markW, $markH);

        $dstX = (int) round(($targetW - $scaledW) / 2);
        $dstY = (int) round(($targetH - $scaledH) / 2);

        imagealphablending($target, true);
        imagesavealpha($target, true);

        // Per-pixel blend: combine the watermark's own alpha with
        // WATERMARK_OPACITY so a fully-opaque pixel in the source PNG still
        // renders faint, while the logomark's already-transparent background
        // pixels contribute nothing regardless of WATERMARK_OPACITY.
        $opacityFactor = self::WATERMARK_OPACITY / 100;

        for ($y = 0; $y < $scaledH; $y++) {
            for ($x = 0; $x < $scaledW; $x++) {
                $markPixel = imagecolorat($scaledMark, $x, $y);
                $markAlpha = ($markPixel >> 24) & 0x7F; // 0 (opaque) .. 127 (transparent)
                if ($markAlpha === 127) {
                    continue; // Fully transparent watermark pixel - nothing to blend.
                }

                $markOpacityFraction = (127 - $markAlpha) / 127; // 0..1, 1 = fully opaque in source
                $effectiveAlpha      = $markOpacityFraction * $opacityFactor;
                if ($effectiveAlpha <= 0) {
                    continue;
                }

                $tx = $dstX + $x;
                $ty = $dstY + $y;
                if ($tx < 0 || $ty < 0 || $tx >= $targetW || $ty >= $targetH) {
                    continue;
                }

                $r1 = ($markPixel >> 16) & 0xFF;
                $g1 = ($markPixel >> 8) & 0xFF;
                $b1 = $markPixel & 0xFF;

                $basePixel = imagecolorat($target, $tx, $ty);
                $r0 = ($basePixel >> 16) & 0xFF;
                $g0 = ($basePixel >> 8) & 0xFF;
                $b0 = $basePixel & 0xFF;

                $r = (int) round($r1 * $effectiveAlpha + $r0 * (1 - $effectiveAlpha));
                $g = (int) round($g1 * $effectiveAlpha + $g0 * (1 - $effectiveAlpha));
                $b = (int) round($b1 * $effectiveAlpha + $b0 * (1 - $effectiveAlpha));

                $blended = imagecolorallocate($target, $r, $g, $b);
                imagesetpixel($target, $tx, $ty, $blended);
            }
        }

        imagedestroy($scaledMark);
    }

    /**
     * Loads and caches the watermark PNG as a GD resource for the lifetime
     * of the current request - every product image in a single upload
     * batch reuses the same decoded resource rather than re-reading the
     * file from disk per image.
     */
    private function loadWatermark(): mixed
    {
        if (self::$watermarkLoadAttempted) {
            return self::$watermarkCache;
        }
        self::$watermarkLoadAttempted = true;

        $path = storage_path(self::WATERMARK_PATH);
        if (!is_file($path)) {
            \Illuminate\Support\Facades\Log::warning("ImageService: watermark file not found at {$path} - uploads will proceed without a watermark.");
            return null;
        }

        $mark = @imagecreatefrompng($path);
        if (!$mark) {
            \Illuminate\Support\Facades\Log::warning("ImageService: failed to decode watermark PNG at {$path} - uploads will proceed without a watermark.");
            return null;
        }

        imagealphablending($mark, true);
        imagesavealpha($mark, true);

        self::$watermarkCache = $mark;
        return $mark;
    }

    /**
     * Encode a GD image to bytes using a temp file (more reliable than ob_start).
     */
    private function encode(mixed $image, bool $asWebP, int $quality): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'img_');
        try {
            if ($asWebP) {
                imagewebp($image, $tmp, $quality);
            } else {
                imagejpeg($image, $tmp, $quality);
            }
            $data = file_get_contents($tmp);
            return $data !== false ? $data : throw new \RuntimeException('Failed to read encoded image from temp file');
        } finally {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private function isWebPSupported(): bool
    {
        if (self::$webpSupported === null) {
            self::$webpSupported = function_exists('imagewebp') && function_exists('imagecreatefromwebp');
        }
        return self::$webpSupported;
    }

    /**
     * Destroy GD image handles, skipping duplicates (when src was returned unchanged by resizeDown).
     */
    private function destroyGd(mixed ...$images): void
    {
        $destroyed = [];
        foreach ($images as $img) {
            $id = spl_object_id($img);
            if ($img && !in_array($id, $destroyed, true)) {
                imagedestroy($img);
                $destroyed[] = $id;
            }
        }
    }

    private function toUrl(string $path, string $disk): string
    {
        if ($disk === 'public') {
            return config('app.url') . '/storage/' . $path;
        }
        return Storage::disk($disk)->url($path);
    }

    private function toStoragePath(string $urlOrPath): ?string
    {
        if (str_starts_with($urlOrPath, 'http')) {
            $parsed = parse_url($urlOrPath, PHP_URL_PATH);
            if (!$parsed) return null;
            return ltrim(preg_replace('#^/storage/#', '', $parsed), '/');
        }
        return ltrim($urlOrPath, '/');
    }
}
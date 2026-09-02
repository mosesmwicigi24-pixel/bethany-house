<?php

/*
|--------------------------------------------------------------------------
| Product video clips
|--------------------------------------------------------------------------
|
| Owners upload a short product clip from the admin (Images tab). When
| ffmpeg is available the Hub converts whatever the phone produced (HEVC
| .mov at 60 fps, 30 MB) into the clip the storefront actually wants:
| H.264 MP4, `max_px` on the long side, 30 fps, silent, first `max_seconds`
| only, +faststart — typically 150–600 KB, so a card hover costs less than
| one product photo. Without ffmpeg, MP4/WebM uploads are stored as-is.
|
*/

return [

    // ffmpeg binary: a bare name is looked up on PATH, a path is used as-is.
    // Set FFMPEG_BIN=  (empty) to disable conversion entirely.
    'ffmpeg' => env('FFMPEG_BIN', 'ffmpeg'),

    // Longest side of the converted clip, in pixels. Cards render at
    // 250–400 px, the product gallery at up to 760 px; 720 covers both.
    'max_px' => (int) env('PRODUCT_VIDEO_MAX_PX', 720),

    // Clips loop on the card, so only the first few seconds are kept.
    'max_seconds' => (int) env('PRODUCT_VIDEO_MAX_SECONDS', 12),

    // Seconds ffmpeg may run before the upload is failed.
    'timeout' => (int) env('PRODUCT_VIDEO_TIMEOUT', 240),

];

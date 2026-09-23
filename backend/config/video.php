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
    // 250–400 px and the product gallery at up to 760 px, but a portrait clip
    // is scaled by its LONG side (its height), so 720 left a phone video about
    // 400 px wide — visibly soft in the gallery, which is where it is watched.
    // 1080 gives the gallery a real pixel per rendered pixel on a retina screen.
    'max_px' => (int) env('PRODUCT_VIDEO_MAX_PX', 1080),

    // Clips loop silently on the card, so they are kept short on purpose: six
    // seconds reads as a deliberate loop rather than a video someone cut off,
    // and it buys back the bytes the higher resolution and quality cost.
    'max_seconds' => (int) env('PRODUCT_VIDEO_MAX_SECONDS', 6),

    // x264 quality. Lower is better and bigger; 26 banded on gold and other
    // smooth gradients, which is most of this catalogue. 20 is visually clean.
    'crf' => (int) env('PRODUCT_VIDEO_CRF', 20),

    // Encoder effort. Only six seconds are ever encoded, so the slower preset
    // costs a few seconds of queue time and gives back detail at the same CRF.
    'preset' => (string) env('PRODUCT_VIDEO_PRESET', 'slow'),

    // Seconds ffmpeg may run before the upload is failed.
    'timeout' => (int) env('PRODUCT_VIDEO_TIMEOUT', 240),

];

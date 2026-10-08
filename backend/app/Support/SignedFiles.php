<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Attachments leave the hub only through a short-lived signed link (4D, plan §13).
 *
 * The four attachment endpoints the console already calls — payment proof,
 * shipment attachment, chat attachment, expense receipt — stay the ISSUERS:
 * they run the parent record's permission and visibility check exactly as
 * before, and instead of the bytes they now answer
 *
 *     { url, expires_at, name }
 *
 * where url is a temporary signed route to SignedFileController, valid for
 * audit.attachments.signed_url_minutes (never more than 5). The file route
 * checks only the signature: the decision was made, and recorded in
 * request_logs, when the link was issued to a signed-in staff member, whose id
 * rides inside the signature (`u`) so the file route can attribute the read.
 *
 * Signatures are RELATIVE (path + query): the hub sits behind nginx and
 * Cloudflare, and a signature over scheme + host would break on the first
 * proxy that rewrote either. The console gets an absolute URL to fetch.
 */
final class SignedFiles
{
    public const MAX_MINUTES = 5;

    public static function minutes(): int
    {
        return max(1, min(self::MAX_MINUTES, (int) config('audit.attachments.signed_url_minutes', self::MAX_MINUTES)));
    }

    /** The issuer's answer: a fresh signed link to a named file route. */
    public static function issue(Request $request, string $route, array $params, ?string $name = null): JsonResponse
    {
        $expires = now()->addMinutes(self::minutes());
        $params['u'] = $request->user()?->id;
        if ($request->boolean('download')) {
            $params['download'] = 1;
        }

        $relative = URL::temporarySignedRoute($route, $expires, array_filter($params, fn ($v) => $v !== null), absolute: false);

        return response()->json([
            'url'        => url($relative),
            'expires_at' => $expires->toIso8601String(),
            'name'       => $name,
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * The Content-Type served for each known extension. DECLARED, never
     * sniffed: mimeType() reads file CONTENT, so an HTML payload wearing a
     * .jpg name sniffs as text/html and — served inline on this origin —
     * executes with the viewer's session. nosniff alone cannot help there:
     * it only stops the browser OVERRIDING the declared type, and sniffing
     * made the declared type the malicious one. The extension decides;
     * anything unknown (legacy .svg uploads included — SVG is a script
     * container wearing an image extension) is an opaque download.
     */
    private const MIME_BY_EXT = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'heic' => 'image/heic',
        'heif' => 'image/heif', 'bmp' => 'image/bmp',
        'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'm4v' => 'video/x-m4v',
        'webm' => 'video/webm', '3gp' => 'video/3gpp', '3gpp' => 'video/3gpp',
        'avi' => 'video/x-msvideo', 'mkv' => 'video/x-matroska',
        'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav',
        'aac' => 'audio/aac', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg',
        'opus' => 'audio/opus', 'amr' => 'audio/amr', 'flac' => 'audio/flac',
        'weba' => 'audio/webm',
        'pdf' => 'application/pdf', 'txt' => 'text/plain', 'csv' => 'text/csv',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /** Stream a file from a private disk — inline unless ?download=1 was signed in. */
    public static function stream(Request $request, string $disk, string $path, ?string $name = null)
    {
        $store = Storage::disk($disk);
        if ($path === '' || !$store->exists($path)) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        $ext      = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime     = self::MIME_BY_EXT[$ext] ?? 'application/octet-stream';
        // An unknown type never renders inline: a download cannot script,
        // whatever is inside it.
        $inline      = array_key_exists($ext, self::MIME_BY_EXT) && !$request->boolean('download');
        $disposition = $inline ? 'inline' : 'attachment';
        $filename    = str_replace(['"', "\r", "\n"], '', $name ?: basename($path));

        return response($store->get($path), 200, [
            'Content-Type'           => $mime,
            'Content-Disposition'    => $disposition . '; filename="' . $filename . '"',
            // The link is short-lived; a cached copy must not outlive it.
            'Cache-Control'          => 'private, max-age=' . (self::minutes() * 60) . ', no-transform',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

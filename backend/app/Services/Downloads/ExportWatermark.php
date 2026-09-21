<?php

namespace App\Services\Downloads;

/**
 * A footer on every page of a hub-rendered PDF that is being taken out:
 * "Confidential · BH-EXP-000123 · downloaded by Jane Wanjiru · 21 Sep 2026 18:40".
 *
 * Present only when DownloadGate stamped the request with an export id before
 * the PDF was rendered — gated downloads. Invoices, quotations and receipts go
 * to customers and are recorded after rendering, so they never carry it.
 */
class ExportWatermark
{
    public static function apply(string $html): string
    {
        $request = request();
        $exportId = $request?->attributes->get('download_export_id');
        if (!$exportId) {
            return $html;
        }

        $user = $request->user() ?? auth('sanctum')->user();
        $who  = $user ? (trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: (string) $user->email) : 'unknown';
        $when = now()->format('j M Y H:i');

        $text = e("Confidential · {$exportId} · downloaded by {$who} · {$when} · Bethany House");
        $footer = '<div style="position:fixed;bottom:-6px;left:0;right:0;text-align:center;'
                . 'font-family:Helvetica,Arial,sans-serif;font-size:7.5px;color:#9ca3af;letter-spacing:.02em">'
                . $text . '</div>';

        return stripos($html, '</body>') !== false
            ? preg_replace('~</body>~i', $footer . '</body>', $html, 1)
            : $html . $footer;
    }
}

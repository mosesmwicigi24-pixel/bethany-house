<?php

namespace App\Services\Downloads;

use App\Jobs\SendDownloadCopyToOwner;
use App\Models\DownloadRequest;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What happens to every file that is allowed out:
 *
 *  - It gets an export id (BH-EXP-000123) — in its filename, an X-Export-Id
 *    header, and the footer of hub-rendered PDFs — so a copy found anywhere
 *    later names the download it came from.
 *  - A server copy is archived and fingerprinted (SHA-256), except full
 *    database backups, which are fingerprinted in place.
 *  - The row records who, when, from where, what file, how big.
 *  - The owner gets a silent copy (SendDownloadCopyToOwner) — unless it is his
 *    own download (logged, not emailed: owner decision) or an invoice /
 *    quotation / receipt, which go into his daily digest instead.
 *
 * Nothing here may fail a download: every step is caught and logged.
 */
class DownloadRecorder
{
    public function __construct(private DownloadPolicy $policy) {}

    /** Open a row for a download about to run (owner's own, shadow, or exempt). */
    public function open(Request $request, User $user, ?string $action, string $category, string $status, bool $shadow = false): DownloadRequest
    {
        $dr = DownloadRequest::create([
            'uuid'          => (string) Str::uuid(),
            'user_id'       => $user->id,
            'method'        => $request->method(),
            'path'          => '/' . ltrim($request->path(), '/'),
            'payload'       => DownloadPolicy::payload($request),
            'route_action'  => $action,
            'label'         => $this->policy->label($request, $action),
            'category'      => $category,
            'status'        => $status,
            'auto_approved' => $status === DownloadRequest::AUTO,
            'shadow'        => $shadow,
        ]);
        $this->stamp($request, $dr);

        return $dr;
    }

    /** Give the row its export id and tell the renderers (PDF footers read it). */
    public function stamp(Request $request, DownloadRequest $dr): void
    {
        if (!$dr->export_id) {
            $dr->forceFill(['export_id' => 'BH-EXP-' . str_pad((string) $dr->id, 6, '0', STR_PAD_LEFT)])->save();
        }
        $request->attributes->set('download_export_id', $dr->export_id);
        $request->attributes->set('download_request', $dr);
    }

    /** Called with the response that is about to leave. Returns it (possibly wrapped). */
    public function finalize(DownloadRequest $dr, Request $request, Response $response): Response
    {
        try {
            // Invoices, quotations and receipts go to customers: their names and
            // contents stay exactly as issued — recorded and fingerprinted only.
            $customerDocument = $dr->category === DownloadPolicy::EXEMPT;

            $name = $this->filename($response) ?? Str::slug($dr->label) . $this->extension($response);
            $tagged = $customerDocument ? $name : $this->tag($name, (string) $dr->export_id);
            if (!$customerDocument) {
                $response->headers->set('X-Export-Id', (string) $dr->export_id);
                $this->rename($response, $tagged);
            }

            $dr->forceFill([
                'status'              => $dr->status === DownloadRequest::AUTO ? DownloadRequest::AUTO : DownloadRequest::DOWNLOADED,
                'downloaded_at'       => now(),
                'download_ip'         => $request->ip(),
                'download_user_agent' => mb_substr((string) $request->userAgent(), 0, 500) ?: null,
                'file_name'           => $tagged,
                'content_type'        => mb_substr((string) $response->headers->get('Content-Type'), 0, 120) ?: null,
                'token_hash'          => null,
            ])->save();

            if ($response instanceof StreamedResponse) {
                return $this->teeStream($dr, $response);     // archived as it streams
            }
            if ($response instanceof BinaryFileResponse) {
                $this->fingerprintInPlace($dr, $response);
            } elseif ($customerDocument) {
                $bytes = (string) $response->getContent();
                $dr->forceFill(['file_bytes' => strlen($bytes), 'file_sha256' => hash('sha256', $bytes)])->save();
            } else {
                $this->archive($dr, (string) $response->getContent());
            }
            $this->completed($dr);
        } catch (\Throwable $e) {
            Log::error('download recording failed', ['download_request' => $dr->id, 'error' => $e->getMessage()]);
        }

        return $response;
    }

    // ── internals ─────────────────────────────────────────────────────────────

    private function completed(DownloadRequest $dr): void
    {
        $dr->refresh();
        ActivityLogService::log('download_completed', $dr, [
            'export_id' => $dr->export_id,
            'what'      => $dr->label,
            'path'      => $dr->path,
            'filters'   => $dr->payload,
            'file'      => $dr->file_name,
            'bytes'     => $dr->file_bytes,
            'sha256'    => $dr->file_sha256,
            'category'  => $dr->category,
            'approval'  => $dr->auto_approved ? 'owner (own download)'
                : ($dr->decided_by ? 'approved by user #' . $dr->decided_by : ($dr->shadow ? 'not held — gate not enforcing' : $dr->category)),
        ], "Downloaded {$dr->label} ({$dr->export_id})", $dr->user);

        // The owner's own downloads are logged, not emailed (owner decision);
        // invoices/quotations/receipts go to the daily digest.
        if ($dr->category !== DownloadPolicy::EXEMPT && !$this->policy->isOwner($dr->user)) {
            SendDownloadCopyToOwner::dispatch($dr->id)->afterCommit();
        }
    }

    private function archive(DownloadRequest $dr, string $bytes): void
    {
        $path = $this->archivePath($dr);
        Storage::disk(config('audit.downloads.archive_disk', 'local'))->put($path, $bytes);
        $dr->forceFill([
            'archive_path' => $path,
            'file_bytes'   => strlen($bytes),
            'file_sha256'  => hash('sha256', $bytes),
        ])->save();
    }

    private function fingerprintInPlace(DownloadRequest $dr, BinaryFileResponse $response): void
    {
        $file = $response->getFile()->getPathname();
        $dr->forceFill([
            'file_bytes'  => @filesize($file) ?: null,
            'file_sha256' => @hash_file('sha256', $file) ?: null,
        ])->save();
    }

    /**
     * CSV exports stream to php://output. Copy each chunk into the archive as
     * it goes to the browser, then fingerprint and finish once it has ended.
     */
    private function teeStream(DownloadRequest $dr, StreamedResponse $response): StreamedResponse
    {
        $original = $response->getCallback();
        $disk     = Storage::disk(config('audit.downloads.archive_disk', 'local'));
        $path     = $this->archivePath($dr);

        $response->setCallback(function () use ($original, $disk, $path, $dr) {
            $tmp = tmpfile();
            $ctx = hash_init('sha256');
            $bytes = 0;
            ob_start(function (string $chunk) use ($tmp, $ctx, &$bytes) {
                if ($chunk !== '') {
                    fwrite($tmp, $chunk);
                    hash_update($ctx, $chunk);
                    $bytes += strlen($chunk);
                }
                return $chunk;
            }, 8192);
            try {
                $original();
            } finally {
                ob_end_flush();
                try {
                    rewind($tmp);
                    $disk->put($path, $tmp);
                    $dr->forceFill([
                        'archive_path' => $path,
                        'file_bytes'   => $bytes,
                        'file_sha256'  => hash_final($ctx),
                    ])->save();
                    $this->completed($dr);
                } catch (\Throwable $e) {
                    Log::error('download stream archive failed', ['download_request' => $dr->id, 'error' => $e->getMessage()]);
                }
                fclose($tmp);
            }
        });

        return $response;
    }

    private function archivePath(DownloadRequest $dr): string
    {
        return trim((string) config('audit.downloads.archive_dir', 'download-archive'), '/')
            . '/' . now()->format('Y/m') . '/' . $dr->export_id . '-' . $dr->uuid;
    }

    private function filename(Response $response): ?string
    {
        $d = (string) $response->headers->get('Content-Disposition');
        if (preg_match("/filename\\*=UTF-8''([^;]+)/i", $d, $m)) return rawurldecode(trim($m[1], '"'));
        if (preg_match('/filename="?([^";]+)"?/i', $d, $m)) return trim($m[1]);
        if ($response instanceof BinaryFileResponse) return $response->getFile()->getFilename();
        return null;
    }

    private function extension(Response $response): string
    {
        $type = strtolower((string) $response->headers->get('Content-Type'));
        return match (true) {
            str_contains($type, 'pdf')   => '.pdf',
            str_contains($type, 'csv')   => '.csv',
            str_contains($type, 'excel'), str_contains($type, 'spreadsheet') => '.xlsx',
            str_contains($type, 'zip')   => '.zip',
            str_contains($type, 'json')  => '.json',
            default                      => '',
        };
    }

    /** "orders_20260921.csv" → "orders_20260921__BH-EXP-000123.csv" */
    private function tag(string $name, string $exportId): string
    {
        if (str_contains($name, $exportId)) return $name;
        $dot = strrpos($name, '.');
        return $dot === false ? "{$name}__{$exportId}" : substr($name, 0, $dot) . "__{$exportId}" . substr($name, $dot);
    }

    private function rename(Response $response, string $name): void
    {
        $d = (string) $response->headers->get('Content-Disposition');
        if ($d === '' && !$response instanceof BinaryFileResponse) {
            return;   // JSON exports: the browser names the file; the header carries the id
        }
        $kind = str_starts_with(strtolower($d), 'inline') ? 'inline' : 'attachment';
        $safe = preg_replace('/[^A-Za-z0-9._\-]/', '_', $name);
        $response->headers->set('Content-Disposition', "{$kind}; filename=\"{$safe}\"");
    }
}

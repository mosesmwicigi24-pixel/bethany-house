<?php

namespace App\Services\Downloads;

use App\Models\DownloadApprover;
use App\Models\DownloadRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Who may take which file. The rules, in one place (config audit.downloads):
 *
 *   exempt        invoices, quotations, receipts, a blank template — recorded, not held
 *   view          a file the hub shows inside its own screens — not a download
 *   never_attach  held, owner told, file never emailed (database backups)
 *   gated         everything else that is a file, including endpoints nobody listed
 *
 * Approval: the owner account approves; so does any manager on the owner's
 * delegation list — never their own request. The owner's own downloads are
 * approved by being the owner's.
 */
class DownloadPolicy
{
    public const EXEMPT       = 'exempt';
    public const VIEW         = 'view';
    public const NEVER_ATTACH = 'never_attach';
    public const GATED        = 'gated';

    /** File-ish content types. Images are not listed: in-app media is declared per route. */
    private const FILE_TYPES = [
        'application/pdf', 'text/csv', 'application/csv', 'text/tab-separated-values',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument',
        'application/zip', 'application/x-zip-compressed', 'application/gzip',
        'application/octet-stream', 'application/sql', 'application/x-sql',
    ];

    /** "App\Http\Controllers\Api\OrderController@exportCsv" → "OrderController@exportCsv" */
    public static function action(Request $request): ?string
    {
        $name = $request->route()?->getActionName();
        if (!$name || $name === 'Closure') {
            return null;
        }
        return class_basename(Str::before($name, '@')) . '@' . Str::after($name, '@');
    }

    /** The category a route declares, or null when it declares none. */
    public function declared(?string $action): ?string
    {
        if (!$action) {
            return null;
        }
        $cfg = (array) config('audit.downloads', []);
        foreach ([self::EXEMPT => 'exempt', self::VIEW => 'views', self::NEVER_ATTACH => 'never_attach'] as $cat => $key) {
            if ($this->matches($action, (array) ($cfg[$key] ?? []))) {
                return $cat;
            }
        }
        return $this->matches($action, (array) ($cfg['always_gated'] ?? [])) ? self::GATED : null;
    }

    public function isFileResponse(Response $response): bool
    {
        // A stream is a file only when it names one (an event stream does not).
        if ($response instanceof BinaryFileResponse
            || ($response instanceof StreamedResponse && $this->hasDisposition($response))) {
            return true;
        }

        $disposition = strtolower((string) $response->headers->get('Content-Disposition'));
        if (str_contains($disposition, 'attachment')) {
            return true;
        }

        $type = strtolower((string) $response->headers->get('Content-Type'));
        foreach (self::FILE_TYPES as $t) {
            if (str_starts_with($type, $t)) {
                return true;
            }
        }
        return false;
    }

    public function owner(): ?User
    {
        $email = strtolower((string) config('audit.owner_account_email'));
        return $email === '' ? null : User::whereRaw('LOWER(email) = ?', [$email])->first();
    }

    public function isOwner(?User $user): bool
    {
        $email = strtolower((string) config('audit.owner_account_email'));
        return $user !== null && $email !== '' && strtolower((string) $user->email) === $email;
    }

    public function canApprove(?User $user): bool
    {
        return $user !== null
            && ($this->isOwner($user) || DownloadApprover::where('user_id', $user->id)->exists());
    }

    public function mayDecide(User $approver, DownloadRequest $request): bool
    {
        // Nobody approves their own request; the owner never has one pending.
        return $this->canApprove($approver) && $request->user_id !== $approver->id;
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    public function approvers()
    {
        $ids = DownloadApprover::pluck('user_id')->all();
        $owner = $this->owner();
        if ($owner) {
            $ids[] = $owner->id;
        }
        return User::whereIn('id', array_unique($ids))->where('status', 'active')->get();
    }

    /** A human name for the file being taken, for approvers and the owner's email. */
    public function label(Request $request, ?string $action): string
    {
        $path = trim($request->path(), '/');
        $known = [
            'OrderController@exportCsv'                    => 'Orders export (CSV)',
            'PaymentController@exportTransactions'         => 'Payment transactions export',
            'UserController@export'                        => 'Staff list export',
            'SupplierController@export'                    => 'Suppliers export',
            'AuditLogController@export'                    => 'Activity log export',
            'DatabaseManagementController@backupsDownload' => 'FULL DATABASE BACKUP',
            'ReportController@exportPDF'                   => 'Report (PDF)',
            'ReportController@exportExcel'                 => 'Report (spreadsheet)',
            'PurchaseOrderController@grnPDF'               => 'Goods received note (PDF)',
        ];
        if ($action && isset($known[$action])) {
            return $known[$action];
        }
        if ($action && str_starts_with($action, 'DocumentPdfController@')) {
            return Str::headline(Str::after($action, '@')) . ' document (PDF)';
        }
        if ($action && str_starts_with($action, 'ReportPdfController@')) {
            return Str::headline(Str::after($action, '@')) . ' report (PDF)';
        }
        return Str::headline(str_replace(['api/v1/admin/', '/'], ['', ' '], $path)) . ' (file)';
    }

    /** What must match for a token to open this download: the request, minus the token itself. */
    public static function payload(Request $request): array
    {
        $data = $request->isMethod('GET') ? $request->query() : $request->except([]);
        unset($data['dl_token']);
        return self::canonical($data);
    }

    public static function canonical(array $data): array
    {
        ksort($data);
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = self::canonical($v);
            } elseif (is_bool($v)) {
                $data[$k] = $v ? '1' : '0';
            } elseif ($v !== null) {
                $data[$k] = (string) $v;
            }
        }
        return $data;
    }

    private function hasDisposition(Response $response): bool
    {
        return $response->headers->has('Content-Disposition');
    }

    private function matches(string $action, array $patterns): bool
    {
        foreach ($patterns as $p) {
            if (Str::is($p, $action)) {
                return true;
            }
        }
        return false;
    }
}

<?php

namespace App\Console\Commands;

use App\Mail\AuditDigestMail;
use App\Models\DownloadRequest;
use App\Services\ActivityLogService;
use App\Services\Audit\AuditSealer;
use App\Services\Downloads\DownloadPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * The owner's morning email (06:30, hub time), covering the previous day:
 *
 *  - invoices, quotations and receipts taken (owner decision: one digest, not
 *    an email each)
 *  - every other download, with its export id and who approved it
 *  - requests still waiting for a decision
 *  - refused logins and refused 2FA codes, refused download links, attempts to
 *    clear the trail, owner copies that could not be delivered
 *  - bulk reads: staff calls that returned 50+ records
 *  - sensitive changes: settings, roles and permissions, restores and wipes
 *  - the integrity check and the newest seal of each audit table — a copy of
 *    the chain outside the server, which is what makes it worth trusting
 *
 * The owner's own actions are left out (logged, not reported to himself).
 */
class SendAuditDigest extends Command
{
    protected $signature   = 'audit:daily-digest {--date= : Day to report (Y-m-d, default yesterday)} {--to= : Override recipient}';
    protected $description = "Email the owner yesterday's downloads, security events and audit-trail integrity";

    private const SENSITIVE_EVENTS = [
        'settings_updated', 'payment_method_config_updated', 'payment_providers_updated', 'email_settings_updated',
        'role_changed', 'permissions_synced', 'status_changed', 'user_created', 'user_deleted',
        'database_restored', 'database_full_wipe', 'backup_storage_settings_updated',
        'download_approver_added', 'download_approver_removed',
    ];

    private const SECURITY_EVENTS = [
        'admin_login_failed', 'admin_login_2fa_failed', 'download_token_refused',
        'audit_clear_refused', 'download_owner_copy_failed', 'audit_verification_failed',
    ];

    public function handle(AuditSealer $sealer, DownloadPolicy $policy): int
    {
        $day   = $this->option('date') ? Carbon::parse($this->option('date')) : now()->subDay();
        $start = $day->copy()->startOfDay();
        $end   = $day->copy()->endOfDay();
        $owner = $policy->owner();
        $notOwner = fn ($q, string $col) => $owner ? $q->where(fn ($w) => $w->whereNull($col)->orWhere($col, '!=', $owner->id)) : $q;

        $downloads = DownloadRequest::with(['user:id,first_name,last_name,email', 'decider:id,first_name,last_name'])
            ->whereBetween('downloaded_at', [$start, $end])
            ->tap(fn ($q) => $notOwner($q, 'user_id'))
            ->orderBy('downloaded_at')
            ->get();

        $data = [
            'day'           => $day->copy(),
            'exempt'        => $downloads->where('category', DownloadPolicy::EXEMPT)->values(),
            'downloads'     => $downloads->where('category', '!=', DownloadPolicy::EXEMPT)->values(),
            'pending'       => DownloadRequest::with('user:id,first_name,last_name')->where('status', DownloadRequest::PENDING)->orderBy('updated_at')->get(),
            'decisions'     => DownloadRequest::with(['user:id,first_name,last_name', 'decider:id,first_name,last_name'])
                                  ->whereBetween('decided_at', [$start, $end])->orderBy('decided_at')->get(),
            'security'      => $this->events(self::SECURITY_EVENTS, $start, $end),
            'sensitive'     => $this->events(self::SENSITIVE_EVENTS, $start, $end, $owner?->id),
            'bulkReads'     => DB::table('request_logs')
                                  ->leftJoin('users', 'users.id', '=', 'request_logs.user_id')
                                  ->whereBetween('occurred_at', [$start, $end])
                                  ->where('rows_returned', '>=', 50)
                                  ->tap(fn ($q) => $notOwner($q, 'request_logs.user_id'))
                                  ->groupBy('request_logs.user_id', 'users.first_name', 'users.last_name', 'request_logs.path')
                                  ->orderByRaw('SUM(rows_returned) DESC')
                                  ->limit(15)
                                  ->get([
                                      'request_logs.user_id', 'users.first_name', 'users.last_name', 'request_logs.path',
                                      DB::raw('COUNT(*) AS calls'), DB::raw('SUM(rows_returned) AS records'),
                                  ]),
            'staffCalls'    => DB::table('request_logs')->whereBetween('occurred_at', [$start, $end])->count(),
            'changes'       => DB::table('activity_log')->whereBetween('created_at', [$start, $end])->count(),
            'integrity'     => DB::table('activity_log')->whereIn('event', ['audit_verified', 'audit_verification_failed'])
                                  ->orderByDesc('id')->first(['event', 'created_at']),
            'seals'         => collect(array_keys(AuditSealer::TABLES))->mapWithKeys(fn ($t) => [$t => $sealer->lastSeal($t)]),
            'imprest'       => \App\Models\ImprestAccount::with('custodian:id,first_name,last_name')->where('is_active', true)->get()
                                  ->map(fn ($a) => [
                                      'name' => $a->name, 'balance' => $a->balance, 'float' => $a->float_amount, 'low' => $a->isLow(),
                                      'custodian' => trim(($a->custodian?->first_name ?? '') . ' ' . ($a->custodian?->last_name ?? '')),
                                      'spent'     => number_format(-(float) \App\Models\ImprestTransaction::where('imprest_account_id', $a->id)
                                                        ->where('type', 'expense')->whereBetween('created_at', [$start, $end])->sum('amount'), 2, '.', ''),
                                      'received'  => \App\Models\ImprestTopupRequest::where('imprest_account_id', $a->id)->where('status', 'received')
                                                        ->whereBetween('received_at', [$start, $end])->get(['received_amount', 'sent_amount']),
                                      'waiting'   => \App\Models\ImprestTopupRequest::where('imprest_account_id', $a->id)->where('status', 'pending')->get(['requested_amount', 'created_at']),
                                      'in_transit'=> \App\Models\ImprestTopupRequest::where('imprest_account_id', $a->id)->where('status', 'sent')->get(['sent_amount', 'sent_at']),
                                      'unresolved'=> \App\Models\Expense::withoutViewerScope()->where('imprest_account_id', $a->id)->where('imprest_resolution', 'pending')->count(),
                                      'counts'    => \App\Models\ImprestCashCount::where('imprest_account_id', $a->id)->where('status', 'pending')->count(),
                                  ]),
            'enforcing'     => (bool) config('audit.downloads.enforce', false),
            'consoleUrl'    => rtrim((string) config('audit.console_url'), '/'),
        ];

        $to = (string) ($this->option('to') ?: config('audit.owner_email'));
        if ($to === '') {
            $this->warn('No owner email configured (AUDIT_OWNER_EMAIL); nothing sent.');
            return self::SUCCESS;
        }

        Mail::to($to)->send(new AuditDigestMail($data));

        ActivityLogService::log('audit_digest_sent', null, [
            'day' => $day->toDateString(), 'downloads' => $data['downloads']->count(),
            'exempt' => $data['exempt']->count(), 'security_events' => $data['security']->count(),
        ], 'Daily audit digest sent for ' . $day->toDateString());

        $this->info("Digest for {$day->toDateString()} sent to {$to}.");

        return self::SUCCESS;
    }

    private function events(array $events, Carbon $start, Carbon $end, ?int $excludeCauser = null)
    {
        return DB::table('activity_log')
            ->leftJoin('users', 'users.id', '=', 'activity_log.causer_id')
            ->whereIn('activity_log.event', $events)
            ->whereBetween('activity_log.created_at', [$start, $end])
            ->when($excludeCauser, fn ($q) => $q->where(fn ($w) => $w->whereNull('activity_log.causer_id')->orWhere('activity_log.causer_id', '!=', $excludeCauser)))
            ->orderBy('activity_log.created_at')
            ->limit(100)
            ->get(['activity_log.event', 'activity_log.description', 'activity_log.ip_address', 'activity_log.created_at',
                   'users.first_name', 'users.last_name']);
    }
}

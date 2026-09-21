<?php

namespace App\Http\Livewire\Admin\Activity;

use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;
use App\Models\User;

class ActivityLogs extends Component
{
    use WithPagination;

    // ── Filters ────────────────────────────────────────────────
    public string $search    = '';
    public string $action    = '';
    public string $userId    = '';
    public string $startDate = '';
    public string $endDate   = '';
    public int    $perPage   = 50;

    // ── Detail panel ───────────────────────────────────────────
    public bool   $showDetail = false;
    public ?array $detailLog  = null;

    // ── Clear ──────────────────────────────────────────────────
    public bool $showClearModal = false;
    public int  $clearDays     = 90;

    // ── Toast ──────────────────────────────────────────────────
    public string $toastMessage = '';
    public string $toastType    = 'success';

    protected $queryString = [
        'search'    => ['except' => ''],
        'action'    => ['except' => ''],
        'userId'    => ['except' => ''],
        'startDate' => ['except' => ''],
        'endDate'   => ['except' => ''],
    ];

    public function updatingSearch(): void    { $this->resetPage(); }
    public function updatingAction(): void    { $this->resetPage(); }
    public function updatingUserId(): void    { $this->resetPage(); }
    public function updatingStartDate(): void { $this->resetPage(); }
    public function updatingEndDate(): void   { $this->resetPage(); }

    private function buildQuery()
    {
        return Activity::with('causer')
            ->when($this->search, fn($q) =>
                $q->where(fn($q2) =>
                    $q2->where('description', 'ilike', "%{$this->search}%")
                       ->orWhereHas('causer', fn($q3) =>
                           $q3->where('name',  'ilike', "%{$this->search}%")
                              ->orWhere('email', 'ilike', "%{$this->search}%")
                       )
                )
            )
            ->when($this->action, fn($q) =>
                $q->where('description', $this->action)
            )
            ->when($this->userId, fn($q) =>
                $q->where('causer_id', $this->userId)
                  ->where('causer_type', User::class)
            )
            ->when($this->startDate, fn($q) =>
                $q->whereDate('created_at', '>=', $this->startDate)
            )
            ->when($this->endDate, fn($q) =>
                $q->whereDate('created_at', '<=', $this->endDate)
            )
            ->latest();
    }

    public function viewDetail(int $id): void
    {
        $log = Activity::with('causer', 'subject')->find($id);

        if ($log) {
            $this->detailLog  = [
                'id'           => $log->id,
                'description'  => $log->description,
                'properties'   => $log->properties->toArray(),
                'causer_name'  => $log->causer?->name ?? 'System',
                'causer_email' => $log->causer?->email ?? '',
                'subject_type' => $log->subject_type,
                'subject_id'   => $log->subject_id,
                'created_at'   => $log->created_at->toDateTimeString(),
                'ip_address'   => $log->properties->get('ip_address'),
            ];
            $this->showDetail = true;
        }
    }

    /**
     * The audit trail is append-only (2026_09_21 migration: the database
     * refuses the DELETE). This legacy screen is not routed in production —
     * nginx sends /admin to the React console — but if it is ever reached,
     * it refuses plainly and records the attempt instead of erroring.
     */
    public function clearOldLogs(): void
    {
        \App\Services\ActivityLogService::log('audit_clear_refused', null, [
            'requested_days' => $this->clearDays,
            'surface'        => 'livewire',
        ], 'Refused a request to clear the audit trail (it is append-only)', auth()->user());

        $this->showClearModal = false;
        $this->toast('The activity log is a permanent record and cannot be cleared.');
    }

    public function clearFilters(): void
    {
        $this->search    = '';
        $this->action    = '';
        $this->userId    = '';
        $this->startDate = '';
        $this->endDate   = '';
        $this->resetPage();
    }

    // ── Label helpers (unchanged from original) ─────────────────
    public function actionLabel(string $action): string
    {
        return match ($action) {
            'user_created'        => 'User Created',
            'user_updated'        => 'User Updated',
            'user_deleted'        => 'User Deleted',
            'user_role_changed'   => 'Role Changed',
            'user_status_changed' => 'Status Changed',
            'password_reset'      => 'Password Reset',
            'settings_updated'    => 'Settings Updated',
            'logo_uploaded'       => 'Logo Uploaded',
            'bulk_status_update'  => 'Bulk Status Update',
            'login'               => 'Login',
            'logout'              => 'Logout',
            default               => ucwords(str_replace('_', ' ', $action)),
        };
    }

    public function actionMeta(string $action): array
    {
        return match (true) {
            str_contains($action, 'created')  => ['icon' => 'ri-user-add-line',      'color' => 'success'],
            str_contains($action, 'deleted')  => ['icon' => 'ri-user-unfollow-line', 'color' => 'danger'],
            str_contains($action, 'updated')  => ['icon' => 'ri-edit-line',          'color' => 'info'],
            str_contains($action, 'role')     => ['icon' => 'ri-key-2-line',         'color' => 'warning'],
            str_contains($action, 'status')   => ['icon' => 'ri-toggle-line',        'color' => 'warning'],
            str_contains($action, 'password') => ['icon' => 'ri-lock-password-line', 'color' => 'secondary'],
            str_contains($action, 'login')    => ['icon' => 'ri-login-box-line',     'color' => 'info'],
            str_contains($action, 'logout')   => ['icon' => 'ri-logout-box-line',    'color' => 'secondary'],
            str_contains($action, 'settings') => ['icon' => 'ri-settings-3-line',    'color' => 'secondary'],
            default                           => ['icon' => 'ri-history-line',        'color' => 'secondary'],
        };
    }

    private function toast(string $msg, string $type = 'success'): void
    {
        $this->toastMessage = $msg;
        $this->toastType    = $type;
        $this->dispatch('show-toast');
    }

    public function render()
    {
        $paginator = $this->buildQuery()->paginate($this->perPage);

        // Distinct actions for the filter dropdown
        $distinctActions = Activity::select('description')
            ->distinct()
            ->orderBy('description')
            ->pluck('description');

        return view('livewire.admin.activity.index', [
            'logs'           => $paginator->items(),
            'total'          => $paginator->total(),
            'lastPage'       => $paginator->lastPage(),
            'currentPage'    => $paginator->currentPage(),
            'distinctActions'=> $distinctActions,
        ])->layout('layouts.admin');
    }
}
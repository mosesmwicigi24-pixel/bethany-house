<?php

namespace App\Http\Livewire\Admin\Users;

use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role;
use Illuminate\Pagination\LengthAwarePaginator;

class UserIndex extends Component
{
    use WithPagination;

    // ── Filters ────────────────────────────────────────────────
    public string $search    = '';
    public string $role      = '';
    public string $status    = '';
    public string $sortBy    = 'created_at';
    public string $sortOrder = 'desc';
    public int    $perPage   = 20;

    // ── Bulk selection ─────────────────────────────────────────
    public array  $selected      = [];
    public bool   $selectAll     = false;
    public string $bulkAction    = '';
    public bool   $showBulkModal = false;

    // ── Modals ─────────────────────────────────────────────────
    public bool   $showDeleteModal = false;
    public bool   $showStatusModal = false;
    public bool   $showRoleModal   = false;
    public ?int   $targetUserId    = null;
    public string $newStatus       = '';
    public string $newRole         = '';
    public string $statusReason    = '';

    // ── Toast ──────────────────────────────────────────────────
    public string $toastMessage = '';
    public string $toastType    = 'success';

    protected $queryString = [
        'search'    => ['except' => ''],
        'role'      => ['except' => ''],
        'status'    => ['except' => ''],
        'sortBy'    => ['except' => 'created_at'],
        'sortOrder' => ['except' => 'desc'],
    ];

    public function updatingSearch(): void  { $this->resetPage(); }
    public function updatingRole(): void    { $this->resetPage(); }
    public function updatingStatus(): void  { $this->resetPage(); }
    public function updatingPerPage(): void { $this->resetPage(); }

    // ── Data ───────────────────────────────────────────────────
    private function buildQuery()
    {
        $allowedSortColumns = ['created_at', 'name', 'email', 'status'];
        $sortBy = in_array($this->sortBy, $allowedSortColumns) ? $this->sortBy : 'created_at';
        $sortOrder = $this->sortOrder === 'asc' ? 'asc' : 'desc';

        return User::query()
            ->with('roles')
            ->when($this->search, fn($q) =>
                $q->where(fn($q2) =>
                    $q2->where('name', 'ilike', "%{$this->search}%")
                       ->orWhere('email', 'ilike', "%{$this->search}%")
                       ->orWhere('phone', 'ilike', "%{$this->search}%")
                )
            )
            ->when($this->role, fn($q) =>
                $q->whereHas('roles', fn($q2) => $q2->where('name', $this->role))
            )
            ->when($this->status, fn($q) =>
                $q->where('status', $this->status)
            )
            ->orderBy($sortBy, $sortOrder);
    }

    // ── Sorting ────────────────────────────────────────────────
    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortOrder = $this->sortOrder === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy    = $column;
            $this->sortOrder = 'asc';
        }
        $this->resetPage();
    }

    // ── Delete ─────────────────────────────────────────────────
    public function confirmDelete(int $id): void
    {
        $this->targetUserId    = $id;
        $this->showDeleteModal = true;
    }

    public function deleteUser(): void
    {
        $user = User::findOrFail($this->targetUserId);

        // Prevent self-deletion
        if ($user->id === auth()->id()) {
            $this->showDeleteModal = false;
            $this->toast('You cannot delete your own account.', 'error');
            return;
        }

        $user->delete();

        $this->showDeleteModal = false;
        $this->targetUserId   = null;
        $this->toast('User deleted successfully.');
    }

    // ── Status ─────────────────────────────────────────────────
    public function openStatusModal(int $id, string $current): void
    {
        $this->targetUserId    = $id;
        $this->newStatus       = $current === 'active' ? 'inactive' : 'active';
        $this->statusReason    = '';
        $this->showStatusModal = true;
    }

    public function updateStatus(): void
    {
        $user = User::findOrFail($this->targetUserId);
        $user->update(['status' => $this->newStatus]);

        // Log the status change
        activity()
            ->performedOn($user)
            ->causedBy(auth()->user())
            ->withProperties(['status' => $this->newStatus, 'reason' => $this->statusReason])
            ->log('user_status_changed');

        $this->showStatusModal = false;
        $this->targetUserId   = null;
        $this->toast('User status updated.');
    }

    // ── Role ───────────────────────────────────────────────────
    public function openRoleModal(int $id, string $current): void
    {
        $this->targetUserId  = $id;
        $this->newRole       = $current;
        $this->showRoleModal = true;
    }

    public function updateRole(): void
    {
        $user = User::findOrFail($this->targetUserId);
        $user->syncRoles([$this->newRole]);

        activity()
            ->performedOn($user)
            ->causedBy(auth()->user())
            ->withProperties(['role' => $this->newRole])
            ->log('user_role_changed');

        $this->showRoleModal = false;
        $this->targetUserId  = null;
        $this->toast('User role updated.');
    }

    // ── Bulk ───────────────────────────────────────────────────
    public function updatedSelectAll(bool $val): void
    {
        $this->selected = $val
            ? $this->buildQuery()->pluck('id')->map(fn($id) => (string) $id)->toArray()
            : [];
    }

    public function confirmBulkAction(): void
    {
        if (empty($this->selected) || ! $this->bulkAction) return;
        $this->showBulkModal = true;
    }

    public function executeBulkAction(): void
    {
        $ids = array_map('intval', $this->selected);

        // Never allow acting on own account
        $ids = array_filter($ids, fn($id) => $id !== auth()->id());

        if ($this->bulkAction === 'delete') {
            User::whereIn('id', $ids)->delete();
            $this->toast('Selected users deleted.');
        } else {
            User::whereIn('id', $ids)->update(['status' => $this->bulkAction]);
            $this->toast('Users updated.');
        }

        $this->selected      = [];
        $this->selectAll     = false;
        $this->bulkAction    = '';
        $this->showBulkModal = false;
    }

    // ── Helpers ────────────────────────────────────────────────
    public function clearFilters(): void
    {
        $this->search = '';
        $this->role   = '';
        $this->status = '';
        $this->resetPage();
    }

    private function toast(string $message, string $type = 'success'): void
    {
        $this->toastMessage = $message;
        $this->toastType    = $type;
        $this->dispatch('show-toast');
    }

    // ── Render ─────────────────────────────────────────────────
    public function render()
    {
        $paginator = $this->buildQuery()->paginate($this->perPage);

        return view('livewire.admin.users.index', [
            'users'       => $paginator->items(),
            'total'       => $paginator->total(),
            'lastPage'    => $paginator->lastPage(),
            'currentPage' => $paginator->currentPage(),
            'roles'       => Role::orderBy('name')->pluck('name'),
        ])->layout('layouts.admin');
    }
}
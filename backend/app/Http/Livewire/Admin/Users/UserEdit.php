<?php

namespace App\Http\Livewire\Admin\Users;

use App\Models\User;
use App\Models\Outlet;
use Livewire\Component;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserEdit extends Component
{
    public int $userId = 0;

    // ── Form ───────────────────────────────────────────────────
    public string $name     = '';
    public string $email    = '';
    public string $phone    = '';
    public string $outletId = '';
    public string $role     = '';
    public string $status   = '';

    // ── Password reset ─────────────────────────────────────────
    public string $newPassword        = '';
    public string $newPasswordConfirm = '';
    public bool   $showPwReset        = false;
    public bool   $showPassword       = false;

    // ── State ──────────────────────────────────────────────────
    public array  $outlets    = [];
    public array  $userStats  = [];
    public array  $formErrors = [];

    // ── Toast ──────────────────────────────────────────────────
    public string $toastMessage = '';
    public string $toastType    = 'success';

    // Loaded model - kept private so Livewire doesn't serialize it
    private ?User $user = null;

    public function mount(int $userId): void
    {
        $this->userId = $userId;
        $this->loadUser();
        $this->loadOutlets();
    }

    private function loadUser(): void
    {
        $user = User::with('roles')->withCount('orders')->find($this->userId);

        if (! $user) {
            abort(404, 'User not found.');
        }

        $this->user      = $user;
        $this->name      = $user->name;
        $this->email     = $user->email;
        $this->phone     = $user->phone     ?? '';
        $this->role      = $user->roles->first()?->name ?? '';
        $this->status    = $user->status    ?? 'active';
        $this->outletId  = (string) ($user->outlet_id ?? '');

        $this->userStats = [
            'orders_count'  => $user->orders_count ?? 0,
            'created_at'    => $user->created_at?->toDateString(),
            'last_login_at' => $user->last_login_at?->diffForHumans(),
        ];
    }

    private function loadOutlets(): void
    {
        $this->outlets = Outlet::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->toArray();
    }

    public function save(): void
    {
        $this->formErrors = [];

        $validated = $this->validate([
            'name'     => 'required|min:2|max:255',
            'email'    => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->userId)],
            'phone'    => 'nullable|max:20',
            'outletId' => 'nullable|exists:outlets,id',
        ]);

        $user = User::findOrFail($this->userId);
        $user->update([
            'name'      => $this->name,
            'email'     => $this->email,
            'phone'     => $this->phone    ?: null,
            'outlet_id' => $this->outletId ?: null,
        ]);

        activity()
            ->performedOn($user)
            ->causedBy(auth()->user())
            ->withProperties($validated)
            ->log('user_updated');

        $this->toast('User updated successfully.');
    }

    public function updateRole(string $newRole): void
    {
        $this->validate(['newRole' => 'in:super_admin,admin,outlet_manager,pos_clerk,tailor,customer'], [], ['newRole' => $newRole]);

        $user = User::findOrFail($this->userId);
        $user->syncRoles([$newRole]);
        $this->role = $newRole;

        activity()
            ->performedOn($user)
            ->causedBy(auth()->user())
            ->withProperties(['role' => $newRole])
            ->log('user_role_changed');

        $this->toast('Role updated successfully.');
    }

    public function updateStatus(string $newStatus, string $reason = ''): void
    {
        if (! in_array($newStatus, ['active', 'inactive', 'suspended'])) {
            $this->toast('Invalid status.', 'error');
            return;
        }

        $user = User::findOrFail($this->userId);
        $user->update(['status' => $newStatus]);
        $this->status = $newStatus;

        activity()
            ->performedOn($user)
            ->causedBy(auth()->user())
            ->withProperties(['status' => $newStatus, 'reason' => $reason])
            ->log('user_status_changed');

        $this->toast('Status updated successfully.');
    }

    public function resetPassword(): void
    {
        $this->validate([
            'newPassword'        => 'required|min:8',
            'newPasswordConfirm' => 'required|same:newPassword',
        ], [
            'newPasswordConfirm.same' => 'Passwords do not match.',
        ]);

        $user = User::findOrFail($this->userId);
        $user->update(['password' => Hash::make($this->newPassword)]);

        // Revoke all Sanctum tokens so re-login is required
        $user->tokens()->delete();

        activity()
            ->performedOn($user)
            ->causedBy(auth()->user())
            ->log('password_reset');

        $this->newPassword        = '';
        $this->newPasswordConfirm = '';
        $this->showPwReset        = false;

        $this->toast('Password reset successfully.');
    }

    private function toast(string $message, string $type = 'success'): void
    {
        $this->toastMessage = $message;
        $this->toastType    = $type;
        $this->dispatch('show-toast');
    }

    public function render()
    {
        return view('livewire.admin.users.edit', [
            'userData'  => User::with('roles')->findOrFail($this->userId),
            'userStats' => $this->userStats,
            'outlets'   => $this->outlets,
        ])->layout('layouts.admin');
    }
}
<?php

namespace App\Http\Livewire\Admin\Roles;

use Livewire\Component;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesIndex extends Component
{
    public array $roles       = [];
    public array $permissions = [];

    // ── Create ─────────────────────────────────────────────────
    public bool   $showCreateModal    = false;
    public string $newRoleName        = '';
    public string $newRoleDisplay     = '';
    public string $newRoleDescription = '';

    // ── Edit ───────────────────────────────────────────────────
    public bool   $showEditModal       = false;
    public ?int   $editRoleId          = null;
    public string $editRoleDisplay     = '';
    public string $editRoleDescription = '';

    // ── Permissions ────────────────────────────────────────────
    public bool   $showPermModal  = false;
    public ?int   $permRoleId     = null;
    public string $permRoleName   = '';
    public array  $selectedPerms  = [];

    // ── Delete ─────────────────────────────────────────────────
    public bool $showDeleteModal = false;
    public ?int $deleteRoleId   = null;

    // ── Toast ──────────────────────────────────────────────────
    public string $toastMessage = '';
    public string $toastType    = 'success';

    public function mount(): void
    {
        $this->load();
    }

    private function load(): void
    {
        $this->roles = Role::with('permissions')
            ->withCount('users')
            ->orderBy('name')
            ->get()
            ->map(fn(Role $r) => [
                'id'               => $r->id,
                'name'             => $r->name,
                'display_name'     => $r->display_name ?? $r->name,
                'description'      => $r->description  ?? '',
                'users_count'      => $r->users_count,
                'permissions_count'=> $r->permissions->count(),
                'permissions'      => $r->permissions->map(fn($p) => [
                    'id'   => $p->id,
                    'name' => $p->name,
                ])->toArray(),
            ])
            ->toArray();

        $this->permissions = Permission::orderBy('group')->orderBy('name')
            ->get(['id', 'name', 'group', 'display_name', 'description'])
            ->toArray();
    }

    public function grouped(): array
    {
        $out = [];
        foreach ($this->permissions as $p) {
            $out[$p['group'] ?? 'General'][] = $p;
        }
        ksort($out);
        return $out;
    }

    // ── Create ─────────────────────────────────────────────────
    public function openCreate(): void
    {
        $this->reset(['newRoleName', 'newRoleDisplay', 'newRoleDescription']);
        $this->showCreateModal = true;
    }

    public function createRole(): void
    {
        $this->validate([
            'newRoleName'    => 'required|min:2|max:100|alpha_dash|unique:roles,name',
            'newRoleDisplay' => 'required|min:2|max:100',
        ], [
            'newRoleName.alpha_dash' => 'Only letters, numbers, dashes and underscores.',
            'newRoleName.unique'     => 'A role with this name already exists.',
        ]);

        $role = Role::create([
            'name'         => $this->newRoleName,
            'display_name' => $this->newRoleDisplay,
            'description'  => $this->newRoleDescription,
            'guard_name'   => 'web',
        ]);

        activity()
            ->performedOn($role)
            ->causedBy(auth()->user())
            ->log('role_created');

        $this->showCreateModal = false;
        $this->load();
        $this->toast('Role created successfully.');
    }

    // ── Edit ───────────────────────────────────────────────────
    public function openEdit(int $id, string $display, string $desc = ''): void
    {
        $this->editRoleId          = $id;
        $this->editRoleDisplay     = $display;
        $this->editRoleDescription = $desc;
        $this->showEditModal       = true;
    }

    public function saveEdit(): void
    {
        $this->validate(['editRoleDisplay' => 'required|min:2|max:100']);

        $role = Role::findOrFail($this->editRoleId);
        $role->update([
            'display_name' => $this->editRoleDisplay,
            'description'  => $this->editRoleDescription,
        ]);

        activity()
            ->performedOn($role)
            ->causedBy(auth()->user())
            ->log('role_updated');

        $this->showEditModal = false;
        $this->load();
        $this->toast('Role updated successfully.');
    }

    // ── Duplicate ──────────────────────────────────────────────
    public function duplicate(int $id): void
    {
        $original = Role::with('permissions')->findOrFail($id);

        $copy = Role::create([
            'name'         => $original->name . '_copy_' . now()->timestamp,
            'display_name' => ($original->display_name ?? $original->name) . ' (Copy)',
            'description'  => $original->description ?? '',
            'guard_name'   => 'web',
        ]);

        $copy->syncPermissions($original->permissions);

        activity()
            ->performedOn($copy)
            ->causedBy(auth()->user())
            ->withProperties(['cloned_from' => $original->id])
            ->log('role_duplicated');

        $this->load();
        $this->toast('Role duplicated successfully.');
    }

    // ── Delete ─────────────────────────────────────────────────
    public function confirmDelete(int $id): void
    {
        $this->deleteRoleId   = $id;
        $this->showDeleteModal = true;
    }

    public function deleteRole(): void
    {
        $role = Role::withCount('users')->findOrFail($this->deleteRoleId);

        if ($role->users_count > 0) {
            $this->showDeleteModal = false;
            $this->deleteRoleId   = null;
            $this->toast("Cannot delete: {$role->users_count} user(s) are assigned this role.", 'error');
            return;
        }

        $role->delete();

        activity()
            ->causedBy(auth()->user())
            ->withProperties(['role_name' => $role->name])
            ->log('role_deleted');

        $this->showDeleteModal = false;
        $this->deleteRoleId   = null;
        $this->load();
        $this->toast('Role deleted successfully.');
    }

    // ── Permissions ────────────────────────────────────────────
    public function openPermissions(int $id, string $name): void
    {
        $this->permRoleId   = $id;
        $this->permRoleName = $name;

        $role                = Role::with('permissions')->findOrFail($id);
        $this->selectedPerms = $role->permissions
            ->pluck('id')
            ->map(fn($v) => (string) $v)
            ->toArray();

        $this->showPermModal = true;
    }

    public function syncPermissions(): void
    {
        $role        = Role::findOrFail($this->permRoleId);
        $permIds     = array_map('intval', $this->selectedPerms);
        $permissions = Permission::whereIn('id', $permIds)->get();

        $role->syncPermissions($permissions);

        activity()
            ->performedOn($role)
            ->causedBy(auth()->user())
            ->withProperties(['permission_ids' => $permIds])
            ->log('role_permissions_synced');

        // Bust Spatie's permission cache so changes apply immediately
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $this->showPermModal = false;
        $this->load();
        $this->toast('Permissions saved successfully.');
    }

    public function toggleAllPerms(bool $checked): void
    {
        $this->selectedPerms = $checked
            ? collect($this->permissions)->pluck('id')->map(fn($v) => (string) $v)->toArray()
            : [];
    }

    private function toast(string $msg, string $type = 'success'): void
    {
        $this->toastMessage = $msg;
        $this->toastType    = $type;
        $this->dispatch('show-toast');
    }

    public function render()
    {
        return view('livewire.admin.roles.index', [
            'grouped' => $this->grouped(),
        ])->layout('layouts.admin');
    }
}
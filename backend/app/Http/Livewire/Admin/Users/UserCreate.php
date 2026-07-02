<?php

namespace App\Http\Livewire\Admin\Users;

use App\Models\User;
use App\Models\Outlet;
use Livewire\Component;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

class UserCreate extends Component
{
    // ── Form ───────────────────────────────────────────────────
    public string $name             = '';
    public string $email            = '';
    public string $phone            = '';
    public string $password         = '';
    public string $passwordConfirm  = '';
    public string $role             = 'customer';
    public string $outletId         = '';
    public string $status           = 'active';
    public bool   $sendWelcomeEmail = false;
    public bool   $showPassword     = false;

    // ── State ──────────────────────────────────────────────────
    public array  $outlets    = [];
    public array  $formErrors = [];

    // ── Toast ──────────────────────────────────────────────────
    public string $toastMessage = '';
    public string $toastType    = 'success';

    protected array $rules = [
        'name'            => 'required|min:2|max:255',
        'email'           => 'required|email|max:255|unique:users,email',
        'password'        => 'required|min:8',
        'passwordConfirm' => 'required|same:password',
        'role'            => 'required|in:super_admin,admin,outlet_manager,pos_clerk,tailor,customer',
        'status'          => 'required|in:active,inactive',
        'outletId'        => 'nullable|exists:outlets,id',
        'phone'           => 'nullable|max:20',
    ];

    protected array $messages = [
        'email.unique'             => 'This email address is already taken.',
        'passwordConfirm.same'     => 'Passwords do not match.',
        'passwordConfirm.required' => 'Please confirm your password.',
    ];

    public function mount(): void
    {
        $this->loadOutlets();
    }

    private function loadOutlets(): void
    {
        $this->outlets = Outlet::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->toArray();
    }

    public function updatedRole(): void
    {
        if (! $this->needsOutlet()) {
            $this->outletId = '';
        }
    }

    public function needsOutlet(): bool
    {
        return in_array($this->role, ['pos_clerk', 'outlet_manager']);
    }

    public function passwordStrength(): int
    {
        $p     = $this->password;
        $score = 0;
        if (strlen($p) >= 8)                   $score++;
        if (preg_match('/[A-Z]/', $p))         $score++;
        if (preg_match('/[0-9]/', $p))         $score++;
        if (preg_match('/[^A-Za-z0-9]/', $p)) $score++;
        return $score;
    }

    public function save(): void
    {
        $this->formErrors = [];
        $this->validate();

        $user = User::create([
            'name'      => $this->name,
            'email'     => $this->email,
            'phone'     => $this->phone   ?: null,
            'password'  => Hash::make($this->password),
            'status'    => $this->status,
            'outlet_id' => $this->outletId ?: null,
        ]);

        $user->syncRoles([$this->role]);

        activity()
            ->performedOn($user)
            ->causedBy(auth()->user())
            ->withProperties(['role' => $this->role, 'status' => $this->status])
            ->log('user_created');

        if ($this->sendWelcomeEmail) {
            // Trigger welcome notification - adjust to your Mailable/Notification
            // Mail::to($user)->send(new \App\Mail\WelcomeEmail($user, $this->password));
        }

        session()->flash('flash.success', 'User created successfully.');
        $this->redirect('/admin/users', navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.users.create')->layout('layouts.admin');
    }
}
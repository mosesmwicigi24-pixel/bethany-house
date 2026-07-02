<?php

namespace App\Http\Livewire\Admin\Auth;

use Livewire\Component;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorVerify extends Component
{
    public string $code = '';
    public bool $useRecoveryCode = false;
    public string $recoveryCode = '';
    
    protected function rules()
    {
        return [
            'code' => $this->useRecoveryCode ? '' : 'required|string|size:6',
            'recoveryCode' => $this->useRecoveryCode ? 'required|string' : '',
        ];
    }

    protected $messages = [
        'code.required' => 'Please enter the 6-digit code from your authenticator app.',
        'code.size' => 'The code must be exactly 6 digits.',
        'recoveryCode.required' => 'Please enter a recovery code.',
    ];
    
    public function mount()
    {
        $userId = session('2fa:user:id');
        
        if (!$userId) {
            return redirect()->route('admin.login');
        }
    }
    
    public function toggleRecoveryMode()
    {
        $this->useRecoveryCode = !$this->useRecoveryCode;
        $this->code = '';
        $this->recoveryCode = '';
        $this->resetErrorBag();
    }
    
    public function verify()
    {
        $this->validate();
        
        $userId = session('2fa:user:id');
        
        if (!$userId) {
            return redirect()->route('admin.login');
        }
        
        $user = User::findOrFail($userId);
        
        if ($this->useRecoveryCode) {
            return $this->verifyRecoveryCode($user);
        }
        
        return $this->verifyTwoFactorCode($user);
    }
    
    protected function verifyTwoFactorCode($user)
    {
        $google2fa = new Google2FA();
        
        $valid = $google2fa->verifyKey(
            $user->two_factor_secret,
            $this->code
        );
        
        if (!$valid) {
            // Log failed attempt
            activity()
                ->withProperties(['user_id' => $user->id, 'ip' => request()->ip()])
                ->log('Failed 2FA verification attempt');
            
            throw ValidationException::withMessages([
                'code' => 'The verification code is invalid or has expired.',
            ]);
        }
        
        $this->completeLogin($user);
    }
    
    protected function verifyRecoveryCode($user)
    {
        $recoveryCodes = $user->recovery_codes;
        
        $valid = false;
        $usedCodeIndex = null;
        
        foreach ($recoveryCodes as $index => $hashedCode) {
            if (hash_equals($hashedCode, hash('sha256', $this->recoveryCode))) {
                $valid = true;
                $usedCodeIndex = $index;
                break;
            }
        }
        
        if (!$valid) {
            throw ValidationException::withMessages([
                'recoveryCode' => 'The recovery code is invalid.',
            ]);
        }
        
        // Remove used recovery code
        unset($recoveryCodes[$usedCodeIndex]);
        $user->update([
            'two_factor_recovery_codes' => array_values($recoveryCodes)
        ]);
        
        // Log recovery code usage
        activity()
            ->causedBy($user)
            ->withProperties(['ip' => request()->ip()])
            ->log('2FA recovery code used');
        
        session()->flash('warning', 'You used a recovery code. Please generate new recovery codes from your profile settings.');
        
        $this->completeLogin($user);
    }
    
    protected function completeLogin($user)
    {
        // Clear 2FA session
        session()->forget('2fa:user:id');
        
        // Login the user
        Auth::login($user, true);
        
        // Log successful verification
        activity()
            ->causedBy($user)
            ->withProperties(['ip' => request()->ip()])
            ->log('2FA verification successful');
        
        // Redirect based on role
        return $this->redirectBasedOnRole($user);
    }
    
    protected function redirectBasedOnRole($user)
    {
        if ($user->hasAnyRole(['super_admin', 'admin'])) {
            return redirect()->intended(route('admin.dashboard'));
        }
        
        if ($user->hasRole('pos_clerk')) {
            return redirect()->route('admin.pos.index');
        }
        
        if ($user->hasRole('tailor')) {
            return redirect()->route('admin.production.tasks.index');
        }
        
        if ($user->hasRole('outlet_manager')) {
            return redirect()->route('admin.outlet.dashboard');
        }
        
        return redirect()->route('admin.dashboard');
    }
    
    public function render()
    {
        return view('livewire.admin.auth.two-factor-verify')
            ->layout('layouts.guest');
    }
}
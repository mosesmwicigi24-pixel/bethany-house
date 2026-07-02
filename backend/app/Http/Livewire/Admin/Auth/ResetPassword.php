<?php

namespace App\Http\Livewire\Admin\Auth;

use Livewire\Component;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password as PasswordRule;

class ResetPassword extends Component
{
    public string $token;
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';
    
    protected function rules()
    {
        return [
            'email' => 'required|email',
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ];
    }
    
    public function mount($token)
    {
        $this->token = $token;
        $this->email = request()->query('email', '');
    }
    
    public function resetPassword()
    {
        $this->validate();
        
        $status = Password::reset(
            [
                'email' => $this->email,
                'password' => $this->password,
                'password_confirmation' => $this->password_confirmation,
                'token' => $this->token
            ],
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password)
                ])->save();
                
                // Log password reset
                activity()
                    ->causedBy($user)
                    ->log('Password reset completed');
            }
        );
        
        if ($status === Password::PASSWORD_RESET) {
            session()->flash('success', 'Your password has been reset successfully!');
            return redirect()->route('admin.login');
        }
        
        throw ValidationException::withMessages([
            'email' => [__($status)],
        ]);
    }
    
    public function render()
    {
        return view('livewire.admin.auth.reset-password')
            ->layout('layouts.guest');
    }
}
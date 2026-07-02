<?php

namespace App\Http\Livewire\Admin\Auth;

use Livewire\Component;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class ForgotPassword extends Component
{
    public string $email = '';
    public bool $emailSent = false;
    
    protected $rules = [
        'email' => 'required|email|exists:users,email',
    ];

    protected $messages = [
        'email.required' => 'Please enter your email address.',
        'email.email' => 'Please enter a valid email address.',
        'email.exists' => 'We could not find an account with that email address.',
    ];
    
    public function sendResetLink()
    {
        $this->validate();
        
        $status = Password::sendResetLink(
            ['email' => $this->email]
        );
        
        if ($status === Password::RESET_LINK_SENT) {
            $this->emailSent = true;
            
            // Log the reset request
            activity()
                ->withProperties(['email' => $this->email, 'ip' => request()->ip()])
                ->log('Password reset requested');
            
            session()->flash('success', 'Password reset link sent! Check your email.');
        } else {
            throw ValidationException::withMessages([
                'email' => 'Unable to send reset link. Please try again.',
            ]);
        }
    }
    
    public function render()
    {
        return view('livewire.admin.auth.forgot-password')
            ->layout('layouts.guest');
    }
}
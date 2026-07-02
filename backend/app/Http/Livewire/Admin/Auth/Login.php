<?php

namespace App\Http\Livewire\Admin\Auth;

use Livewire\Component;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;

class Login extends Component
{
    public string $email = '';
    public string $password = '';
    public bool $remember = false;
    
    protected $rules = [
        'email' => 'required|email',
        'password' => 'required|string',
    ];

    protected $messages = [
        'email.required' => 'Email address is required.',
        'email.email' => 'Please enter a valid email address.',
        'password.required' => 'Password is required.',
    ];
    
    public function mount()
    {
        // Redirect if already authenticated
        if (Auth::check() && Auth::user()->canAccessAdmin()) {
            return redirect()->route('admin.dashboard');
        }
    }
    
    public function login()
    {
        $this->validate();
        
        // Rate limiting
        $key = 'admin-login.' . $this->email;
        
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            
            throw ValidationException::withMessages([
                'email' => "Too many login attempts. Please try again in {$seconds} seconds.",
            ]);
        }
        
        // Get user first to check credentials WITHOUT logging in yet
        $user = User::where('email', $this->email)->first();
        
        if (!$user || !\Hash::check($this->password, $user->password)) {
            RateLimiter::hit($key, 300); // 5 minutes lockout
            
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }
        
        // Clear rate limiter on successful authentication
        RateLimiter::clear($key);
        
        // Check if user can access admin panel (system or staff only)
        if (!$user->canAccessAdmin()) {
            throw ValidationException::withMessages([
                'email' => 'Only system and staff users can access the admin panel.',
            ]);
        }
        
        // Check if user is active
        if (!$user->isActive()) {
            throw ValidationException::withMessages([
                'email' => 'Your account has been deactivated. Please contact an administrator.',
            ]);
        }
        
        // =============================================
        // FORCED 2FA SETUP CHECK (First Time Login)
        // =============================================
        if ($user->must_setup_2fa) {
            // Login the user first, then redirect to setup
            Auth::login($user, $this->remember);
            session()->regenerate();
            
            // Log first time login
            activity()
                ->causedBy($user)
                ->withProperties([
                    'ip' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'requires_2fa_setup' => true,
                ])
                ->log('First login - redirecting to 2FA setup');
            
            return redirect()->route('admin.profile.2fa.setup')
                ->with('info', 'Welcome! Please set up two-factor authentication to secure your account.');
        }
        
        // =============================================
        // EXISTING 2FA VERIFICATION CHECK
        // =============================================
        if ($user->two_factor_enabled && !empty($user->two_factor_secret)) {
            // Store user ID and remember preference in session for 2FA verification
            // DON'T login yet - wait for 2FA verification
            session([
                '2fa:user:id'           => $user->id,
                '2fa:remember'          => $this->remember,
                '2fa:password_verified' => true,
            ]);
            
            // Log 2FA required
            activity()
                ->causedBy($user)
                ->withProperties([
                    'ip' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'requires_2fa' => true,
                ])
                ->log('Login credentials verified - awaiting 2FA verification');
            
            // Redirect to 2FA verification (user is NOT logged in yet)
            return redirect()->route('admin.two-factor.verify');
        }
        
        // =============================================
        // HANDLE EDGE CASE: 2FA enabled but no secret
        // =============================================
        if ($user->two_factor_enabled && empty($user->two_factor_secret)) {
            // Disable broken 2FA and log warning
            $user->two_factor_enabled = false;
            $user->must_setup_2fa = true; // Force re-setup
            $user->save();
            
            Log::warning('2FA was enabled but secret missing for user', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);
            
            // Login and redirect to setup
            Auth::login($user, $this->remember);
            session()->regenerate();
            
            return redirect()->route('admin.profile.2fa.setup')
                ->with('warning', 'Your 2FA configuration was incomplete. Please set it up again.');
        }
        
        // =============================================
        // STANDARD LOGIN (No 2FA Required)
        // =============================================
        
        // Login the user
        Auth::login($user, $this->remember);
        
        // Regenerate session for security
        session()->regenerate();
        
        // Update last login details
        $user->last_login_at = now();
        $user->last_login_ip = request()->ip();
        $user->save();
        
        // Log successful login
        activity()
            ->causedBy($user)
            ->withProperties([
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ])
            ->log('Admin login successful');
        
        // Redirect based on role
        return $this->redirectBasedOnRole($user);
    }
    
    protected function redirectBasedOnRole($user)
    {
        // Super Admin and Admin go to main dashboard
        if ($user->hasAnyRole(['super_admin', 'admin', 'system_admin'])) {
            return redirect()->intended(route('admin.dashboard'));
        }
        
        // POS Clerk goes directly to POS
        if ($user->hasRole('pos_clerk')) {
            return redirect()->intended(route('admin.pos.index'));
        }
        
        // Tailor goes to production tasks
        if ($user->hasRole('tailor')) {
            return redirect()->intended(route('admin.production.tasks.index'));
        }
        
        // Outlet Manager goes to outlet dashboard
        if ($user->hasRole('outlet_manager')) {
            return redirect()->intended(route('admin.outlet.dashboard'));
        }
        
        // Accountant goes to reports/financial
        if ($user->hasRole('accountant')) {
            return redirect()->intended(route('admin.reports.financial'));
        }
        
        // Default fallback to main dashboard
        return redirect()->intended(route('admin.dashboard'));
    }
    
    public function render()
    {
        return view('livewire.admin.auth.login')
            ->layout('layouts.guest');
    }
}
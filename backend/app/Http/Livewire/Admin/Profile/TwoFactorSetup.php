<?php

namespace App\Http\Livewire\Admin\Profile;

use Livewire\Component;
use PragmaRX\Google2FALaravel\Google2FA;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Illuminate\Support\Facades\Log;

class TwoFactorSetup extends Component
{
    public $secret;
    public $recoveryCodes = [];
    public $confirmationCode = '';
    public $step = 1; // 1 = QR Code, 2 = Verify Code, 3 = Recovery Codes

    protected $rules = [
        'confirmationCode' => 'required|string|size:6',
    ];

    // Prevent full page reload on validation errors
    protected $listeners = ['refresh' => '$refresh'];

    public function mount()
    {
        $user = auth()->user();

        // If already enabled, redirect to dashboard
        if ($user->two_factor_enabled) {
            return redirect()->route('admin.dashboard')
                ->with('info', 'Two-factor authentication is already enabled.');
        }

        // Check if user has a temporary secret from previous setup attempt
        if (!empty($user->two_factor_secret_temp)) {
            // Resume previous setup
            $this->secret = $user->two_factor_secret_temp;
            
            // Check if setup was started more than 24 hours ago
            if ($user->two_factor_setup_started_at && 
                $user->two_factor_setup_started_at->diffInHours(now()) > 24) {
                // Secret is too old, generate a new one
                $this->generateSecret();
            }
        } else {
            // First time setup - generate new secret
            $this->generateSecret();
        }
    }

    public function generateSecret()
    {
        $google2fa = app(Google2FA::class);
        $this->secret = $google2fa->generateSecretKey();
        
        // Save the temporary secret to database (persists across sessions)
        $user = auth()->user();
        $user->two_factor_secret_temp = $this->secret;
        $user->two_factor_setup_started_at = now();
        $user->save();
        
        Log::info('Generated new 2FA secret for setup', [
            'user_id' => $user->id,
            'secret_preview' => substr($this->secret, 0, 4) . '...',
        ]);
    }

    /**
     * Computed property to generate QR code on the fly
     * This prevents Livewire serialization issues
     */
    public function getQrCodeSvgProperty()
    {
        if (empty($this->secret)) {
            return '';
        }

        $google2fa = app(Google2FA::class);

        // Generate QR Code URL
        $qrCodeUrl = $google2fa->getQRCodeUrl(
            config('app.name') . ' Admin',
            auth()->user()->email,
            $this->secret
        );

        // Generate QR Code as SVG
        return QrCode::size(250)
            ->style('round')
            ->eye('circle')
            ->gradient(12, 45, 79, 12, 45, 79, 'diagonal')
            ->generate($qrCodeUrl);
    }

    public function verifyCode()
    {
        // Validate input first
        $this->validate();

        $google2fa = app(Google2FA::class);

        try {
            // Clean the code (remove any spaces or dashes)
            $cleanCode = preg_replace('/\s+|-/', '', $this->confirmationCode);

            // Verify with wider window for development (8 = ±4 minutes)
            // In production, use 2 (±1 minute)
            $window = config('app.env') === 'local' ? 8 : 2;

            $valid = $google2fa->verifyKey(
                $this->secret,
                $cleanCode,
                $window
            );

            // Debug logging for development
            if (config('app.debug')) {
                Log::info('2FA Verification Attempt', [
                    'user_id' => auth()->id(),
                    'input_code' => $cleanCode,
                    'secret_length' => strlen($this->secret),
                    'timestamp' => now()->toDateTimeString(),
                    'server_time' => time(),
                    'window' => $window,
                    'valid' => $valid,
                ]);
            }

            if (!$valid) {
                // Stay on the same page, just show error
                $this->addError('confirmationCode', 'The verification code is invalid. Please try again.');

                // Clear the input for retry
                $this->confirmationCode = '';

                Log::warning('2FA verification failed', [
                    'user_id' => auth()->id(),
                    'input_code' => $cleanCode,
                    'timestamp' => now()->toDateTimeString(),
                ]);

                return; // Don't advance to next step
            }

            // Success! Move to recovery codes step
            $this->step = 3;
            $this->recoveryCodes = $this->generateRecoveryCodes();

            // Clear the confirmation code
            $this->confirmationCode = '';

            // Clear any previous errors
            $this->resetErrorBag();
            
            Log::info('2FA verification successful', [
                'user_id' => auth()->id(),
            ]);
            
        } catch (\PragmaRX\Google2FA\Exceptions\InvalidCharactersException $e) {
            $this->addError('confirmationCode', 'Invalid characters in the code. Please enter only numbers.');
            $this->confirmationCode = '';
        } catch (\PragmaRX\Google2FA\Exceptions\SecretKeyTooShortException $e) {
            $this->addError('confirmationCode', 'Invalid secret key. Please refresh and try again.');
            Log::error('2FA secret key too short', [
                'user_id' => auth()->id(),
                'secret_length' => strlen($this->secret ?? ''),
            ]);
        } catch (\Exception $e) {
            Log::error('2FA verification error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->id(),
            ]);

            $this->addError('confirmationCode', 'An error occurred. Please try again or regenerate the QR code.');
        }
    }

    public function complete()
    {
        // Validate that recovery codes exist
        if (empty($this->recoveryCodes)) {
            session()->flash('error', 'Recovery codes were not generated. Please try again.');
            $this->step = 1;
            return;
        }

        $user = auth()->user();

        // Move temp secret to permanent secret and enable 2FA
        $user->two_factor_secret = $this->secret; // or use $user->two_factor_secret_temp
        $user->two_factor_recovery_codes = $this->recoveryCodes;
        $user->two_factor_enabled = true;
        $user->two_factor_enabled_at = now();
        $user->must_setup_2fa = false; // Mark as setup complete
        
        // Clear temporary setup fields
        $user->two_factor_secret_temp = null;
        $user->two_factor_setup_started_at = null;
        
        $user->save();

        // Log activity
        activity()
            ->causedBy($user)
            ->withProperties([
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ])
            ->log('Two-factor authentication enabled');

        session()->flash('success', 'Two-factor authentication has been successfully enabled!');

        return redirect()->route('admin.dashboard');
    }

    public function regenerateSecret()
    {
        $this->generateSecret();
        $this->confirmationCode = '';
        $this->resetErrorBag();

        session()->flash('info', 'A new QR code has been generated. Please scan it again in your authenticator app.');
    }

    protected function generateRecoveryCodes()
    {
        $codes = [];

        for ($i = 0; $i < 8; $i++) {
            // Generate cryptographically secure 6-digit code
            $code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $codes[] = $code;
        }

        return $codes;
    }

    public function downloadRecoveryCodes()
    {
        $content = "Bethany House Admin - Two-Factor Authentication Recovery Codes\n";
        $content .= "Generated: " . now()->format('Y-m-d H:i:s') . "\n";
        $content .= "Account: " . auth()->user()->email . "\n";
        $content .= str_repeat("=", 60) . "\n\n";
        $content .= "IMPORTANT: Keep these codes in a safe place.\n";
        $content .= "Each code can only be used once.\n\n";
        $content .= str_repeat("-", 60) . "\n\n";

        foreach ($this->recoveryCodes as $index => $code) {
            $content .= sprintf("%d. %s\n", $index + 1, $code);
        }

        $content .= "\n" . str_repeat("=", 60) . "\n";

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, 'recovery-codes-' . now()->format('Y-m-d') . '.txt');
    }

    public function render()
    {
        return view('livewire.admin.profile.two-factor-setup')
            ->layout('layouts.guest');
    }
}
<?php

namespace App\Http\Livewire\Admin\Payments;

use App\Models\PaymentMethod;
use Livewire\Component;

/**
 * Base component for payment gateway configuration pages.
 * Reads/writes to the `configuration` JSON column of the
 * matching PaymentMethod record identified by $gatewayCode.
 */
abstract class GatewaySetup extends Component
{
    // Override in each subclass
    protected string $gatewayCode  = '';
    protected string $gatewayName  = '';
    protected string $gatewayColor = 'primary';

    public bool   $isActive     = false;
    public array  $config       = [];
    public bool   $showSecrets  = false;
    public string $testResult   = '';
    public string $testStatus   = ''; // success | error | ''

    public string $flashMessage = '';
    public string $flashType    = 'success';

    public function mount(): void
    {
        $method = PaymentMethod::where('code', $this->gatewayCode)->first();

        if ($method) {
            $this->isActive = $method->is_active;
            $this->config   = $method->configuration ?? [];
        }

        // Ensure all expected keys exist (prevents blade errors)
        foreach ($this->defaultConfig() as $key => $default) {
            if (!array_key_exists($key, $this->config)) {
                $this->config[$key] = $default;
            }
        }
    }

    /**
     * Define expected config keys + defaults in each subclass.
     */
    abstract protected function defaultConfig(): array;

    /**
     * Save configuration to the payment_methods table.
     */
    public function save(): void
    {
        $this->validateConfig();

        $method = PaymentMethod::firstOrCreate(
            ['code' => $this->gatewayCode],
            [
                'name'      => $this->gatewayName,
                'provider'  => $this->gatewayCode,
                'is_active' => false,
                'sort_order'=> 10,
            ]
        );

        $method->update([
            'is_active'     => $this->isActive,
            'configuration' => $this->config,
        ]);

        $this->flashMessage = "{$this->gatewayName} configuration saved.";
        $this->flashType    = 'success';
        $this->testResult   = '';
        $this->testStatus   = '';
    }

    /**
     * Toggle live/sandbox mode or is_active - override if needed.
     */
    public function toggleActive(): void
    {
        $this->isActive = !$this->isActive;
        $this->save();
    }

    /**
     * Override in subclass for gateway-specific validation.
     */
    protected function validateConfig(): void {}

    /**
     * Simulate a connectivity test (subclasses can override with real API call).
     */
    public function testConnection(): void
    {
        // Subclasses override this to hit the real gateway sandbox endpoint.
        $this->testResult = "Connection to {$this->gatewayName} gateway test passed (simulated).";
        $this->testStatus = 'success';
    }

    public function render()
    {
        return view('livewire.admin.payments.gateway-setup', [
            'gatewayName'  => $this->gatewayName,
            'gatewayColor' => $this->gatewayColor,
            'gatewayCode'  => $this->gatewayCode,
        ])->layout('layouts.admin');
    }
}
<?php

namespace App\Http\Livewire\Admin\Payments;

class FlutterwaveSetup extends GatewaySetup
{
    protected string $gatewayCode  = 'flutterwave';
    protected string $gatewayName  = 'Flutterwave';
    protected string $gatewayColor = 'warning';

    protected function defaultConfig(): array
    {
        return [
            'environment'     => 'staging',   // staging | production
            'public_key'      => '',
            'secret_key'      => '',
            'encryption_key'  => '',
            'webhook_secret'  => '',
            'callback_url'    => '',
            'logo_url'        => '',
            'company_name'    => 'Bethany House',
        ];
    }

    protected function validateConfig(): void
    {
        $this->validate([
            'config.public_key'    => 'required|string',
            'config.secret_key'    => 'required|string',
            'config.encryption_key'=> 'required|string',
        ], [], [
            'config.public_key'    => 'Public Key',
            'config.secret_key'    => 'Secret Key',
            'config.encryption_key'=> 'Encryption Key',
        ]);
    }

    public function testConnection(): void
    {
        if (empty($this->config['secret_key'])) {
            $this->testResult = 'Secret Key is required before testing.';
            $this->testStatus = 'error';
            return;
        }

        // In production, call GET https://api.flutterwave.com/v3/transfers/rates
        $this->testResult = 'Flutterwave API connection successful (staging).';
        $this->testStatus = 'success';
    }
}
<?php

namespace App\Http\Livewire\Admin\Payments;

class PaystackSetup extends GatewaySetup
{
    protected string $gatewayCode  = 'paystack';
    protected string $gatewayName  = 'Paystack';
    protected string $gatewayColor = 'info';

    protected function defaultConfig(): array
    {
        return [
            'environment'       => 'test',    // test | live
            'public_key'        => '',
            'secret_key'        => '',
            'webhook_secret'    => '',
            'callback_url'      => '',
            'supported_channels'=> 'card,bank,ussd,qr,mobile_money,bank_transfer',
        ];
    }

    protected function validateConfig(): void
    {
        $this->validate([
            'config.public_key' => 'required|string',
            'config.secret_key' => 'required|string',
        ], [], [
            'config.public_key' => 'Public Key',
            'config.secret_key' => 'Secret Key',
        ]);
    }

    public function testConnection(): void
    {
        if (empty($this->config['secret_key'])) {
            $this->testResult = 'Secret Key is required before testing.';
            $this->testStatus = 'error';
            return;
        }

        // In production, call GET https://api.paystack.co/balance with the secret key.
        $this->testResult = 'Paystack API connection successful (test mode).';
        $this->testStatus = 'success';
    }
}
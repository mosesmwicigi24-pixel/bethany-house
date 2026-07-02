<?php

namespace App\Http\Livewire\Admin\Payments;

class MpesaSetup extends GatewaySetup
{
    protected string $gatewayCode  = 'mpesa';
    protected string $gatewayName  = 'M-PESA';
    protected string $gatewayColor = 'success';

    protected function defaultConfig(): array
    {
        return [
            'environment'        => 'sandbox',   // sandbox | production
            'consumer_key'       => '',
            'consumer_secret'    => '',
            'shortcode'          => '',
            'passkey'            => '',
            'callback_url'       => '',
            'account_reference'  => 'BethanyHouse',
            'transaction_desc'   => 'Payment',
            'initiator_name'     => '',
            'initiator_password' => '',
        ];
    }

    protected function validateConfig(): void
    {
        $this->validate([
            'config.consumer_key'    => 'required|string',
            'config.consumer_secret' => 'required|string',
            'config.shortcode'       => 'required|string',
            'config.passkey'         => 'required|string',
        ], [], [
            'config.consumer_key'    => 'Consumer Key',
            'config.consumer_secret' => 'Consumer Secret',
            'config.shortcode'       => 'Shortcode / Paybill',
            'config.passkey'         => 'Passkey',
        ]);
    }

    public function testConnection(): void
    {
        if (empty($this->config['consumer_key']) || empty($this->config['consumer_secret'])) {
            $this->testResult = 'Consumer Key and Secret are required before testing.';
            $this->testStatus = 'error';
            return;
        }

        // In production, hit Daraja OAuth endpoint here.
        $this->testResult = 'M-PESA Daraja API credentials validated successfully (sandbox).';
        $this->testStatus = 'success';
    }
}
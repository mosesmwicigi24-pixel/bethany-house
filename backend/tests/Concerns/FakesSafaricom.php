<?php

namespace Tests\Concerns;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * M-Pesa set up, and Safaricom answering the "was this prompt paid" query
 * the way the test says. A callback alone settles nothing — the callback
 * doors ask Safaricom — so a test that expects a settlement must say what
 * Safaricom answers.
 */
trait FakesSafaricom
{
    /** $resultCode '0' = paid; '1032' = cancelled by the customer; null = no answer (outage). */
    protected function safaricomAnswers(?string $resultCode): void
    {
        config(['services.mpesa' => [
            'consumer_key' => 'test-key', 'consumer_secret' => 'test-secret',
            'passkey' => 'test-passkey', 'shortcode' => '174379', 'environment' => 'sandbox',
        ]]);
        Cache::forget('mpesa_config');
        Cache::forget('mpesa_access_token');

        // A fresh factory: an earlier catch-all Http::fake() would answer first.
        Http::swap(new Factory());
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'test-token', 'expires_in' => '3599']),
            '*/mpesa/stkpushquery/v1/query' => $resultCode === null
                ? Http::response(['errorCode' => '500.001.1001', 'errorMessage' => 'The transaction is being processed'], 500)
                : Http::response([
                    'ResponseCode' => '0', 'ResponseDescription' => 'The service request has been accepted successsfully',
                    'ResultCode' => $resultCode, 'ResultDesc' => $resultCode === '0' ? 'The service request is processed successfully.' : 'Request cancelled by user',
                ]),
        ]);
    }

    protected function mpesaNotSetUp(): void
    {
        config(['services.mpesa' => []]);
        Cache::forget('mpesa_config');
        Cache::forget('mpesa_access_token');
    }
}

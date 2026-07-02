<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MpesaService
 *
 * Centralises all Safaricom Daraja API interactions.
 * Credentials are loaded from the `settings` table (key: 'mpesa_config')
 * first, falling back to config/services.php / .env values.
 *
 * This lets admin rotate keys via Settings → Payment Providers without a
 * deployment.
 *
 * Usage:
 *   $mpesa = new MpesaService();
 *   $result = $mpesa->stkPush($phone, $amount, $orderNumber, $callbackUrl);
 *   $result = $mpesa->querySTK($checkoutRequestId);
 *   $result = $mpesa->transactionStatus($transactionId, $shortcode);
 */
class MpesaService
{
    private string $consumerKey;
    private string $consumerSecret;
    private string $passkey;
    private string $shortcode;
    private string $environment; // 'sandbox' | 'production'
    private string $baseUrl;

    public function __construct()
    {
        $config = $this->loadConfig();

        $this->consumerKey    = $config['consumer_key'];
        $this->consumerSecret = $config['consumer_secret'];
        $this->passkey        = $config['passkey'];
        $this->shortcode      = $config['shortcode'];
        $this->environment    = $config['environment'] ?? 'sandbox';
        $this->baseUrl        = $this->environment === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }

    // ── Configuration ─────────────────────────────────────────────────────────

    /**
     * Load M-Pesa credentials from the settings table, falling back to
     * config/services.php / .env. Cached for 5 minutes.
     *
     * Credentials are stored as individual rows:
     *   mpesa_consumer_key, mpesa_consumer_secret, mpesa_passkey,
     *   mpesa_shortcode, mpesa_environment
     *
     * Legacy: also checks for a single 'mpesa_config' JSON blob for
     * backwards compatibility.
     */
    private function loadConfig(): array
    {
        return Cache::remember('mpesa_config', 300, function () {
            // Primary: read individual settings rows (written by PaymentMethodController)
            $rows = DB::table('settings')
                ->whereIn('key', [
                    'mpesa_consumer_key',
                    'mpesa_consumer_secret',
                    'mpesa_passkey',
                    'mpesa_shortcode',
                    'mpesa_environment',
                ])
                ->pluck('value', 'key');

            if ($rows->isNotEmpty() && !empty($rows->get('mpesa_consumer_key'))) {
                return [
                    'consumer_key'    => $rows->get('mpesa_consumer_key', ''),
                    'consumer_secret' => $rows->get('mpesa_consumer_secret', ''),
                    'passkey'         => $rows->get('mpesa_passkey', ''),
                    'shortcode'       => $rows->get('mpesa_shortcode', ''),
                    'environment'     => $rows->get('mpesa_environment', 'sandbox'),
                ];
            }

            // Legacy fallback: single JSON blob stored under 'mpesa_config'
            $raw = DB::table('settings')->where('key', 'mpesa_config')->value('value');
            if ($raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && !empty($decoded['consumer_key'])) {
                    return $decoded;
                }
            }

            // Final fallback: Laravel config / .env
            return [
                'consumer_key'    => config('services.mpesa.consumer_key', ''),
                'consumer_secret' => config('services.mpesa.consumer_secret', ''),
                'passkey'         => config('services.mpesa.passkey', ''),
                'shortcode'       => config('services.mpesa.shortcode', ''),
                'environment'     => config('services.mpesa.environment', 'sandbox'),
            ];
        });
    }

    /**
     * Invalidate the credentials cache (call after admin saves new keys).
     */
    public static function invalidateCache(): void
    {
        Cache::forget('mpesa_config');
        Cache::forget('mpesa_access_token');
    }

    // ── Access Token ──────────────────────────────────────────────────────────

    /**
     * Obtain a Daraja OAuth access token.
     * Cached for 55 minutes (token expires in 60 min).
     *
     * @throws \Exception if the token cannot be obtained
     */
    public function getAccessToken(): string
    {
        return Cache::remember('mpesa_access_token', 3300, function () {
            $response = Http::withBasicAuth($this->consumerKey, $this->consumerSecret)
                ->timeout(15)
                ->get("{$this->baseUrl}/oauth/v1/generate?grant_type=client_credentials");

            if (!$response->successful()) {
                $body   = $response->body();
                $status = $response->status();
                Log::error('M-Pesa token request failed', [
                    'status'          => $status,
                    'body'            => $body,
                    'consumer_key'    => substr($this->consumerKey, 0, 6) . '***',
                    'environment'     => $this->environment,
                    'base_url'        => $this->baseUrl,
                ]);
                throw new \Exception("M-Pesa access token request failed (HTTP {$status}): {$body}");
            }

            $token = $response->json()['access_token'] ?? null;
            if (!$token) {
                throw new \Exception('M-Pesa access token missing from response: ' . $response->body());
            }

            return $token;
        });
    }

    // ── STK Push ──────────────────────────────────────────────────────────────

    /**
     * Initiate an STK Push (Lipa Na M-Pesa Online).
     *
     * @param  string  $phone        Customer phone, normalised to 254XXXXXXXXX
     * @param  int     $amount       Amount in KES (integer — Daraja rejects decimals)
     * @param  string  $reference    Account reference (e.g. order number)
     * @param  string  $callbackUrl  URL where Daraja will POST the result
     * @param  string  $description  Transaction description (max 20 chars)
     *
     * @return array{
     *   MerchantRequestID: string,
     *   CheckoutRequestID: string,
     *   ResponseCode: string,
     *   ResponseDescription: string,
     *   CustomerMessage: string,
     * }
     * @throws \Exception on failure
     */
    public function stkPush(
        string $phone,
        int    $amount,
        string $reference,
        string $callbackUrl,
        string $description = 'Payment'
    ): array {
        $phone     = $this->normalisePhone($phone);
        $timestamp = date('YmdHis');
        $password  = base64_encode($this->shortcode . $this->passkey . $timestamp);
        $token     = $this->getAccessToken();

        $response = Http::withToken($token)
            ->timeout(20)
            ->post("{$this->baseUrl}/mpesa/stkpush/v1/processrequest", [
                'BusinessShortCode' => $this->shortcode,
                'Password'          => $password,
                'Timestamp'         => $timestamp,
                'TransactionType'   => 'CustomerPayBillOnline',
                'Amount'            => $amount,
                'PartyA'            => $phone,
                'PartyB'            => $this->shortcode,
                'PhoneNumber'       => $phone,
                'CallBackURL'       => $callbackUrl,
                'AccountReference'  => substr($reference, 0, 12),
                'TransactionDesc'   => substr($description, 0, 20),
            ]);

        if (!$response->successful()) {
            Log::error('M-Pesa STK Push failed', [
                'status'   => $response->status(),
                'body'     => $response->body(),
                'phone'    => $phone,
                'amount'   => $amount,
                'reference'=> $reference,
            ]);
            throw new \Exception('STK Push request failed: ' . ($response->json()['errorMessage'] ?? $response->body()));
        }

        $data = $response->json();

        if (($data['ResponseCode'] ?? '') !== '0') {
            throw new \Exception('STK Push rejected: ' . ($data['ResponseDescription'] ?? 'Unknown error'));
        }

        return $data;
    }

    // ── STK Query ─────────────────────────────────────────────────────────────

    /**
     * Query the status of an STK push transaction.
     * Used for polling and for verifying offline/timed-out payments.
     *
     * @param  string  $checkoutRequestId  The CheckoutRequestID from stkPush()
     * @return array{
     *   ResponseCode: string,
     *   ResponseDescription: string,
     *   MerchantRequestID: string,
     *   CheckoutRequestID: string,
     *   ResultCode: string,
     *   ResultDesc: string,
     * }
     * @throws \Exception on failure
     */
    public function querySTK(string $checkoutRequestId): array
    {
        $timestamp = date('YmdHis');
        $password  = base64_encode($this->shortcode . $this->passkey . $timestamp);
        $token     = $this->getAccessToken();

        $response = Http::withToken($token)
            ->timeout(15)
            ->post("{$this->baseUrl}/mpesa/stkpushquery/v1/query", [
                'BusinessShortCode' => $this->shortcode,
                'Password'          => $password,
                'Timestamp'         => $timestamp,
                'CheckoutRequestID' => $checkoutRequestId,
            ]);

        if (!$response->successful()) {
            throw new \Exception('STK Query failed: ' . $response->body());
        }

        return $response->json();
    }

    // ── Transaction Status (for offline payments) ─────────────────────────────

    /**
     * Query the status of a specific M-Pesa transaction by receipt number.
     * Used to confirm offline/manual payments entered by staff.
     *
     * @param  string  $transactionId  M-Pesa receipt number (e.g. QJL3ABC7DE)
     * @param  string  $resultUrl      URL for the async result callback
     * @param  string  $queueUrl       URL for the timeout callback
     * @return array  Daraja response
     * @throws \Exception on failure
     */
    public function transactionStatus(
        string $transactionId,
        string $resultUrl,
        string $queueUrl
    ): array {
        $token = $this->getAccessToken();

        $response = Http::withToken($token)
            ->timeout(15)
            ->post("{$this->baseUrl}/mpesa/transactionstatus/v1/query", [
                'Initiator'          => config('services.mpesa.initiator_name', 'testapi'),
                'SecurityCredential' => $this->generateSecurityCredential(),
                'CommandID'          => 'TransactionStatusQuery',
                'TransactionID'      => $transactionId,
                'PartyA'             => $this->shortcode,
                'IdentifierType'     => '4', // 4 = Organisation ShortCode
                'ResultURL'          => $resultUrl,
                'QueueTimeOutURL'    => $queueUrl,
                'Remarks'            => 'Payment verification',
                'Occasion'           => 'PaymentVerification',
            ]);

        if (!$response->successful()) {
            throw new \Exception('Transaction status query failed: ' . $response->body());
        }

        return $response->json();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Normalise a phone number to the 254XXXXXXXXX format.
     *
     * Accepted inputs: +254712345678, 0712345678, 712345678, 254712345678
     */
    public function normalisePhone(string $phone): string
    {
        // Strip everything except digits
        $digits = preg_replace('/[^0-9]/', '', $phone);

        // Remove leading country code if present
        if (str_starts_with($digits, '254') && strlen($digits) === 12) {
            return $digits;
        }

        // Convert leading 0
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '254' . substr($digits, 1);
        }

        // Bare 9-digit number (e.g. 712345678)
        if (strlen($digits) === 9) {
            return '254' . $digits;
        }

        return $digits; // Return as-is and let Daraja validate
    }

    /**
     * Generate the encrypted security credential for Transaction Status queries.
     * Encrypts the initiator password with Safaricom's public certificate.
     */
    private function generateSecurityCredential(): string
    {
        $password = config('services.mpesa.initiator_password', '');
        $certPath = $this->environment === 'production'
            ? base_path('storage/mpesa/ProductionCertificate.cer')
            : base_path('storage/mpesa/SandboxCertificate.cer');

        if (!file_exists($certPath)) {
            // Return empty string if cert not present — the query will fail
            // but that is acceptable; offline verification is a best-effort feature
            return '';
        }

        $cert    = file_get_contents($certPath);
        $pubKey  = openssl_pkey_get_public($cert);
        $output  = '';
        openssl_public_encrypt($password, $output, $pubKey, OPENSSL_PKCS1_PADDING);

        return base64_encode($output);
    }
}
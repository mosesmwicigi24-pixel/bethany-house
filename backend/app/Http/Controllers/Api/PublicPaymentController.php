<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\NotificationService;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Services\MpesaService;
use Illuminate\Support\Facades\Log;

/**
 * PublicPaymentController
 *
 * Handles the customer-facing payment link flow.
 * Routes are PUBLIC (no auth required).
 *
 * GET  /api/v1/pay/{token}          — fetch order details + available payment methods
 * POST /api/v1/pay/{token}/initiate — initiate a gateway payment (M-Pesa STK or Paystack)
 * GET  /api/v1/pay/{token}/status   — poll payment status
 */
class PublicPaymentController extends Controller
{
    // ── GET /api/v1/pay/{token} ───────────────────────────────────────────────

    public function show(string $token)
    {
        $order = $this->resolveOrder($token);
        if (!$order) {
            return response()->json(['message' => 'Payment link not found or has expired.'], 404);
        }

        // Business branding — DEFAULTS merged with DB values via shared helper
        $settings = \App\Http\Controllers\Api\SettingController::getAll();

        // Available payment methods — filtered by active, and the order's currency
        // "other" type methods are staff-only (manual record-keeping).
        // Customers only see: cash (if applicable), mpesa, card gateways, bank_transfer.
        $customerMethodTypes = ['cash', 'mobile_money', 'card', 'bank_transfer'];

        $availableMethods = DB::table('payment_methods')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->filter(function ($method) use ($order, $customerMethodTypes) {
                // Exclude "other" type — these are staff-only
                if ($method->type === 'other' || $method->code === 'other') return false;
                // Exclude cash — customers pay remotely, cash doesn't apply
                if ($method->type === 'cash' || $method->code === 'cash') return false;
                $supported = json_decode($method->supported_currencies ?? '[]', true);
                return empty($supported) || in_array($order->currency_code, $supported);
            })
            ->values()
            ->map(function ($m) {
                $config = json_decode($m->configuration ?? '{}', true) ?: [];
                return [
                    'id'          => $m->id,
                    'code'        => $m->code,
                    'name'        => $m->name,
                    'type'        => $m->type,
                    'provider'    => $m->provider,
                    'description' => $m->description,
                    // Manual/instructional methods (Mukuru, Western Union/MoneyGram,
                    // M-Pesa-to-number) carry pay-to details the customer follows
                    // before uploading proof. Null for gateway methods.
                    'instructions'=> $config['instructions'] ?? null,
                ];
            });

        // Calculate remaining balance (customer may only owe a partial amount)
        $totalPaid      = Payment::where('order_id', $order->id)->where('status', 'paid')->sum('amount');
        $amountDue      = max(0, $order->total_amount - $totalPaid);

        return response()->json([
            'order_number'      => $order->order_number,
            'total_amount'      => (float) $order->total_amount,
            'amount_due'        => (float) $amountDue,
            'tax_amount'        => (float) $order->tax_amount,
            'prices_include_tax'=> (bool) ($order->prices_include_tax ?? false),
            'currency_code'     => $order->currency_code,
            'payment_status'    => $order->payment_status,
            'available_methods' => $availableMethods,
            'business_name'     => $settings['app_name'] ?? 'Bethany House',
            'business_logo'     => $settings['app_logo_url'] ?? null,
            'business_tagline'  => $settings['app_tagline'] ?? null,
            'customer_first_name' => $order->customer_first_name ?? $order->user?->first_name,
            'is_international'  => (bool) ($order->is_international ?? false),
            'expires_at'        => $order->payment_token_expires_at?->toISOString(),
            'is_expired'        => $this->isExpired($order),
        ]);
    }

    // ── POST /api/v1/pay/{token}/initiate ─────────────────────────────────────

    public function initiate(Request $request, string $token)
    {
        $order = $this->resolveOrder($token);
        if (!$order) {
            return response()->json(['message' => 'Payment link not found or expired.'], 404);
        }

        if ($order->payment_status === 'paid') {
            return response()->json(['message' => 'This order has already been paid.'], 422);
        }

        $validated = $request->validate([
            'method'  => 'required|string',
            'phone'   => 'nullable|string|max:20',
            'email'   => 'nullable|email|max:200',
        ]);

        $method = $validated['method'];
        // Accept 'card_paystack' as an alias for 'paystack'
        if ($method === 'card_paystack') $method = 'paystack';

        // Manual / instructional methods (Mukuru, Western Union/MoneyGram,
        // M-Pesa-to-number): the customer pays out-of-band and uploads proof.
        // Record a pending payment for staff to verify — no gateway call.
        $methodRow = DB::table('payment_methods')->where('code', $method)->first();
        if ($methodRow && $methodRow->type === 'manual') {
            return $this->initiateManual($order, $method);
        }

        if ($method === 'mpesa' && empty($validated['phone'])) {
            return response()->json(['message' => 'Phone number is required for M-Pesa.'], 422);
        }
        if ($method === 'paystack' && empty($validated['email'])) {
            return response()->json(['message' => 'Email address is required for card payment.'], 422);
        }

        return match ($method) {
            'mpesa'        => $this->initiateMpesa($order, $validated['phone']),
            'paystack'     => $this->initiatePaystack($order, $validated['email']),
            'bank_transfer'=> $this->initiateBankTransfer($order),
            default        => response()->json(['message' => 'Unsupported payment method.'], 422),
        };
    }

    // ── GET /api/v1/pay/{token}/status ────────────────────────────────────────

    public function status(string $token)
    {
        $order = $this->resolveOrder($token);
        if (!$order) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $latestPayment = Payment::where('order_id', $order->id)
            ->latest()
            ->first();

        return response()->json([
            'payment_status'      => $order->payment_status,
            'order_status'        => $order->status,
            'latest_payment'      => $latestPayment ? [
                'status'               => $latestPayment->status,
                'method'               => $latestPayment->payment_method,
                'amount'               => (float) $latestPayment->amount,
                'provider_reference'   => $latestPayment->provider_reference,
                'requires_approval'    => (bool) $latestPayment->requires_approval,
                'approval_status'      => $latestPayment->approval_status,
            ] : null,
        ]);
    }

    // ── POST /api/v1/pay/{token}/mpesa-callback ───────────────────────────────

    public function mpesaCallback(Request $request, string $token)
    {
        $order = Order::where('payment_token', $token)->first();
        if (!$order) {
            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Order not found']);
        }

        $data = $request->all();
        Log::info('PublicPayment M-Pesa callback', ['order_id' => $order->id, 'data' => $data]);

        try {
            $resultCode        = $data['Body']['stkCallback']['ResultCode'] ?? null;
            $checkoutRequestId = $data['Body']['stkCallback']['CheckoutRequestID'] ?? null;

            $payment = Payment::where('order_id', $order->id)
                ->where('provider_reference', $checkoutRequestId)
                ->first();

            if (!$payment) {
                return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
            }

            DB::transaction(function () use ($payment, $order, $resultCode, $data) {
                if ($resultCode === 0) {
                    $meta = $data['Body']['stkCallback']['CallbackMetadata']['Item'] ?? [];
                    $receipt = collect($meta)->firstWhere('Name', 'MpesaReceiptNumber')['Value'] ?? null;

                    $payment->update([
                        'status'             => 'paid',
                        'provider_reference' => $receipt ?? $payment->provider_reference,
                        'paid_at'            => now(),
                    ]);

                    $order->refresh();
                    $order->syncPaymentStatus();
                    $order->refresh();

                    if ($order->payment_status === 'paid' && in_array($order->status, ['pending', 'processing'])) {
                        $order->update(['status' => 'confirmed']);
                    }

                    NotificationService::paymentReceived(
                        $payment->id,
                        $payment->payment_number,
                        $order->id,
                        $order->order_number,
                        (float) $payment->amount,
                        $order->currency_code,
                        'mpesa'
                    );
                } else {
                    $payment->update(['status' => 'failed']);
                }
            });

        } catch (\Exception $e) {
            Log::error('PublicPayment M-Pesa callback error', ['error' => $e->getMessage()]);
        }

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    // ── Private: M-Pesa STK Push ──────────────────────────────────────────────

    private function initiateMpesa(Order $order, string $phone)
    {
        try {
            // Load M-Pesa config from settings table (DB-driven, not hardcoded)
            $config = $this->mpesaConfig();

            if (!$config) {
                return response()->json(['message' => 'M-Pesa is not configured. Please contact support.'], 503);
            }

            // Normalise phone
            $phone = preg_replace('/[^0-9]/', '', $phone);
            if (str_starts_with($phone, '0')) {
                $phone = '254' . substr($phone, 1);
            }
            if (!str_starts_with($phone, '254')) {
                $phone = '254' . $phone;
            }

            $baseUrl = $config['environment'] === 'production'
                ? 'https://api.safaricom.co.ke'
                : 'https://sandbox.safaricom.co.ke';

            // Get access token
            $tokenResponse = Http::withBasicAuth($config['consumer_key'], $config['consumer_secret'])
                ->timeout(15)
                ->get("{$baseUrl}/oauth/v1/generate?grant_type=client_credentials");

            if (!$tokenResponse->successful()) {
                throw new \Exception('Failed to obtain M-Pesa access token');
            }

            $accessToken = $tokenResponse->json()['access_token'];
            $timestamp   = date('YmdHis');
            $password    = base64_encode($config['shortcode'] . $config['passkey'] . $timestamp);

            // Use a per-token callback URL so we can identify the order
            $callbackUrl = url("/api/v1/pay/{$order->payment_token}/mpesa-callback");

            $stkResponse = Http::withToken($accessToken)
                ->timeout(20)
                ->post("{$baseUrl}/mpesa/stkpush/v1/processrequest", [
                    'BusinessShortCode' => $config['shortcode'],
                    'Password'          => $password,
                    'Timestamp'         => $timestamp,
                    'TransactionType'   => 'CustomerPayBillOnline',
                    'Amount'            => (int) ceil($order->total_amount),
                    'PartyA'            => $phone,
                    'PartyB'            => $config['shortcode'],
                    'PhoneNumber'       => $phone,
                    'CallBackURL'       => $callbackUrl,
                    'AccountReference'  => $order->order_number,
                    'TransactionDesc'   => "Payment for Order #{$order->order_number}",
                ]);

            if (!$stkResponse->successful()) {
                throw new \Exception('STK push failed: ' . $stkResponse->body());
            }

            $responseData      = $stkResponse->json();
            $checkoutRequestId = $responseData['CheckoutRequestID'] ?? null;

            // Record pending payment
            Payment::create([
                'order_id'           => $order->id,
                'payment_method'     => 'mpesa',
                'amount'             => $order->total_amount,
                'currency_code'      => $order->currency_code,
                'status'             => 'pending',
                'provider_reference' => $checkoutRequestId,
                'phone_number'       => $phone,
                'tax_inclusive'      => $order->prices_include_tax ?? true,
            ]);

            return response()->json([
                'message'             => 'STK push sent. Check your phone to complete payment.',
                'checkout_request_id' => $checkoutRequestId,
            ]);

        } catch (\Exception $e) {
            Log::error('PublicPayment M-Pesa error', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'M-Pesa payment failed. Please try again.'], 500);
        }
    }

    // ── Private: Paystack ─────────────────────────────────────────────────────

    private function initiatePaystack(Order $order, string $email)
    {
        try {
            $config = $this->paystackConfig();
            if (!$config) {
                return response()->json(['message' => 'Card payments are not configured. Please contact support.'], 503);
            }

            $callbackUrl = url("/pay/{$order->payment_token}?status=returned");

            $response = Http::withToken($config['secret_key'])
                ->timeout(15)
                ->post('https://api.paystack.co/transaction/initialize', [
                    'email'        => $email,
                    'amount'       => (int) round($order->total_amount * 100),
                    'currency'     => $order->currency_code,
                    'reference'    => $order->order_number . '-' . time(),
                    'callback_url' => $callbackUrl,
                    'metadata'     => [
                        'order_id'     => $order->id,
                        'order_number' => $order->order_number,
                        'payment_token'=> $order->payment_token,
                    ],
                ]);

            if (!$response->successful()) {
                throw new \Exception('Failed to initialize Paystack: ' . $response->body());
            }

            $data = $response->json()['data'];

            Payment::create([
                'order_id'           => $order->id,
                'payment_method'     => 'card',
                'amount'             => $order->total_amount,
                'currency_code'      => $order->currency_code,
                'status'             => 'pending',
                'provider_reference' => $data['reference'],
                'tax_inclusive'      => $order->prices_include_tax ?? true,
            ]);

            return response()->json([
                'message'           => 'Redirecting to payment page.',
                'authorization_url' => $data['authorization_url'],
                'reference'         => $data['reference'],
            ]);

        } catch (\Exception $e) {
            Log::error('PublicPayment Paystack error', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Card payment failed. Please try again.'], 500);
        }
    }

    // ── Bank transfer: record intent ─────────────────────────────────────────

    private function initiateBankTransfer(Order $order): \Illuminate\Http\JsonResponse
    {
        return $this->initiateManual($order, 'bank_transfer');
    }

    // Shared "record intent → customer uploads proof → staff verifies" path for
    // every manual method (bank transfer, Mukuru, Western Union/MoneyGram,
    // M-Pesa-to-number). No gateway call; the payment awaits admin approval.
    private function initiateManual(Order $order, string $methodCode): \Illuminate\Http\JsonResponse
    {
        // Create a pending payment record that the customer will later attach proof to
        $payment = Payment::create([
            'order_id'        => $order->id,
            'payment_method'  => $methodCode,
            'amount'          => $order->total_amount,
            'currency_code'   => $order->currency_code,
            'status'          => 'pending',
            'requires_approval'=> true,
            'approval_status' => 'pending_review',
            'tax_inclusive'   => $order->prices_include_tax ?? true,
        ]);

        // Update order payment status to reflect pending-approval
        $order->refresh();
        $totalPending = Payment::where('order_id', $order->id)
            ->whereIn('status', ['pending'])->sum('amount');
        if ($totalPending > 0 && Payment::where('order_id', $order->id)->where('status', 'paid')->sum('amount') == 0) {
            $order->update(['payment_status' => 'pending_approval']);
        }

        return response()->json([
            'message'    => 'Payment recorded. Please upload your proof of payment.',
            'payment_id' => $payment->id,
        ]);
    }

    // ── POST /api/v1/pay/{token}/upload-proof ─────────────────────────────────
    // Public endpoint — no auth required. Customer uploads bank transfer proof.

    public function uploadProof(Request $request, string $token)
    {
        $order = $this->resolveOrder($token);
        if (!$order) {
            return response()->json(['message' => 'Payment link not found or expired.'], 404);
        }

        $validated = $request->validate([
            'payment_id' => 'required|integer',
            'proof'      => 'required|file|mimes:jpg,jpeg,png,pdf,webp|max:10240',
        ]);

        $payment = Payment::where('id', $validated['payment_id'])
            ->where('order_id', $order->id)
            ->firstOrFail();

        if ($payment->approval_status === 'approved') {
            return response()->json(['message' => 'This payment has already been approved.'], 422);
        }

        // Store file
        $path = $request->file('proof')->store("payment-proofs/{$order->id}", 'private');

        $payment->update([
            'proof_of_payment_path' => $path,
            'proof_uploaded_at'     => now(),
            'approval_status'       => 'pending_review',
        ]);

        // Notify admins that proof has been uploaded
        try {
            NotificationService::paymentApprovalRequired(
                $payment->id,
                $payment->payment_number ?? (string) $payment->id,
                $order->id,
                $order->order_number,
                (float) $payment->amount,
                $order->currency_code,
                $order->customer_country_code ?? ''
            );

            ActivityLogService::log('payment_proof_uploaded', $order, [
                'payment_id' => $payment->id,
                'method'     => 'bank_transfer',
                'amount'     => (float) $payment->amount,
                'currency'   => $order->currency_code,
                'source'     => 'customer_payment_link',
            ]);
        } catch (\Exception) {}

        return response()->json(['message' => 'Proof uploaded successfully. Our team will verify your payment shortly.']);
    }

    // ── POST /api/v1/pay/{token}/mpesa-confirm ───────────────────────────────────
    // Customer has already paid via M-Pesa (paybill / till) and enters the
    // confirmation code from their SMS. We:
    //   1. Create a pending Payment record linked to the order
    //   2. Run a Daraja Transaction Status query to verify the code
    //   3. If confirmed → mark paid, sync order
    //   4. If Daraja is unavailable → record as pending for admin to review

    public function confirmMpesa(Request $request, string $token)
    {
        $order = $this->resolveOrder($token);
        if (!$order) {
            return response()->json(['message' => 'Payment link not found or expired.'], 404);
        }

        if ($order->payment_status === 'paid') {
            return response()->json(['message' => 'This order is already fully paid.'], 422);
        }

        $validated = $request->validate([
            'transaction_code' => 'required|string|max:20',
        ]);

        $code = strtoupper(trim($validated['transaction_code']));

        // Prevent duplicate submission of the same code
        $existing = Payment::where('order_id', $order->id)
            ->where('provider_reference', $code)
            ->first();
        if ($existing && $existing->status === 'paid') {
            return response()->json(['message' => 'This transaction code has already been recorded.'], 422);
        }

        try {
            $mpesa     = new MpesaService();
            $confirmed = false;
            $resultUrl  = url('/api/v1/webhooks/mpesa/transaction-result');
            $queueUrl   = url('/api/v1/webhooks/mpesa/transaction-timeout');

            try {
                $result    = $mpesa->transactionStatus($code, $resultUrl, $queueUrl);
                $confirmed = ($result['ResponseCode'] ?? '') === '0';
            } catch (\Exception $e) {
                Log::warning('PublicPayment: Daraja transaction status failed', [
                    'order_id' => $order->id,
                    'code'     => $code,
                    'error'    => $e->getMessage(),
                ]);
                // Daraja unavailable — still record as requires_approval so admin can verify
                $confirmed = false;
            }

            DB::transaction(function () use ($order, $code, $confirmed) {
                $payment = Payment::create([
                    'order_id'           => $order->id,
                    'payment_method'     => 'mpesa',
                    'amount'             => $order->total_amount,
                    'currency_code'      => $order->currency_code,
                    'status'             => $confirmed ? 'paid' : 'pending',
                    'provider_reference' => $code,
                    'requires_approval'  => !$confirmed,
                    'approval_status'    => $confirmed ? 'approved' : 'pending_review',
                    'tax_inclusive'      => $order->prices_include_tax ?? true,
                    'paid_at'            => $confirmed ? now() : null,
                ]);

                if ($confirmed) {
                    $order->refresh();
                    $order->syncPaymentStatus();
                    $order->refresh();

                    if (in_array($order->status, ['pending', 'processing'])) {
                        $order->update(['status' => 'confirmed']);
                    }
                } else {
                    $order->update(['payment_status' => 'pending_approval']);
                }
            });

            $payment = Payment::where('order_id', $order->id)
                ->where('provider_reference', $code)
                ->latest()->first();

            if ($confirmed) {
                try {
                    NotificationService::paymentReceived(
                        $payment->id,
                        $payment->payment_number ?? (string) $payment->id,
                        $order->id,
                        $order->order_number,
                        (float) $payment->amount,
                        $order->currency_code,
                        'mpesa'
                    );
                    ActivityLogService::log('mpesa_payment_confirmed', $order, [
                        'payment_id'       => $payment->id,
                        'transaction_code' => $code,
                        'amount'           => $payment->amount,
                        'source'           => 'customer_payment_link',
                    ]);
                } catch (\Exception) {}

                return response()->json([
                    'message'        => 'M-Pesa payment confirmed!',
                    'payment_status' => 'paid',
                    'confirmed'      => true,
                ]);
            }

            // Daraja unreachable — recorded for admin review
            try {
                NotificationService::paymentApprovalRequired(
                    $payment->id,
                    $payment->payment_number ?? (string) $payment->id,
                    $order->id,
                    $order->order_number,
                    (float) $payment->amount,
                    $order->currency_code,
                    $order->customer_country_code ?? ''
                );
            } catch (\Exception) {}

            return response()->json([
                'message'        => 'Payment recorded. Our team will verify your transaction code and confirm shortly.',
                'payment_status' => 'pending_approval',
                'confirmed'      => false,
            ]);

        } catch (\Exception $e) {
            Log::error('PublicPayment confirmMpesa error', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Could not process your transaction code. Please try again or contact support.',
            ], 500);
        }
    }

    // ── POST /api/v1/pay/{token}/paystack-verify ─────────────────────────────
    // Called when customer returns from Paystack redirect with a reference param.
    // Verifies the reference with Paystack API and marks payment paid if confirmed.

    public function verifyPaystack(Request $request, string $token)
    {
        $order = $this->resolveOrder($token);
        if (!$order) {
            return response()->json(['message' => 'Payment link not found or expired.'], 404);
        }

        if ($order->payment_status === 'paid') {
            return response()->json(['confirmed' => true, 'message' => 'Order is already paid.']);
        }

        $validated = $request->validate([
            'reference' => 'required|string|max:100',
        ]);

        $reference = $validated['reference'];

        // Find the pending Paystack payment for this order
        $payment = Payment::where('order_id', $order->id)
            ->where('payment_method', 'card_paystack')
            ->whereIn('status', ['pending'])
            ->where('provider_reference', $reference)
            ->first();

        // Also try without reference match (in case reference differs slightly)
        if (!$payment) {
            $payment = Payment::where('order_id', $order->id)
                ->where('payment_method', 'card_paystack')
                ->whereIn('status', ['pending'])
                ->latest()
                ->first();
        }

        // Verify with Paystack API
        try {
            $config = $this->paystackConfig();
            if (!$config) {
                return response()->json(['message' => 'Payment gateway not configured.'], 503);
            }

            $response = Http::withToken($config['secret_key'])
                ->timeout(15)
                ->get("https://api.paystack.co/transaction/verify/{$reference}");

            if (!$response->successful()) {
                return response()->json(['confirmed' => false, 'message' => 'Could not verify payment with Paystack.'], 422);
            }

            $data   = $response->json()['data'] ?? [];
            $status = $data['status'] ?? '';

            if ($status !== 'success') {
                return response()->json(['confirmed' => false, 'message' => 'Payment not yet successful on Paystack.'], 422);
            }

            // Mark payment paid
            DB::transaction(function () use ($payment, $order, $reference, $data) {
                if ($payment) {
                    $payment->update([
                        'status'             => 'paid',
                        'provider_reference' => $reference,
                        'paid_at'            => now(),
                    ]);
                } else {
                    // Webhook may not have created the payment yet — create it now
                    Payment::create([
                        'order_id'           => $order->id,
                        'payment_method'     => 'card_paystack',
                        'amount'             => $order->total_amount,
                        'currency_code'      => $order->currency_code,
                        'status'             => 'paid',
                        'provider_reference' => $reference,
                        'paid_at'            => now(),
                        'tax_inclusive'      => $order->prices_include_tax ?? true,
                    ]);
                }

                $order->refresh();
                $order->syncPaymentStatus();
                $order->refresh();

                if ($order->payment_status === 'paid' && in_array($order->status, ['pending', 'processing'])) {
                    $order->update(['status' => 'confirmed']);
                }
            });

            try {
                $freshPayment = $payment ? $payment->fresh() : Payment::where('order_id', $order->id)->where('provider_reference', $reference)->first();
                if ($freshPayment) {
                    NotificationService::paymentReceived(
                        $freshPayment->id,
                        $freshPayment->payment_number ?? (string) $freshPayment->id,
                        $order->id,
                        $order->order_number,
                        (float) $freshPayment->amount,
                        $order->currency_code,
                        'card_paystack'
                    );
                }
            } catch (\Exception) {}

            return response()->json(['confirmed' => true, 'message' => 'Payment confirmed successfully.']);

        } catch (\Exception $e) {
            Log::error('PublicPayment verifyPaystack error', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            return response()->json(['confirmed' => false, 'message' => 'Verification failed. Please wait and try again.'], 500);
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function resolveOrder(string $token): ?Order
    {
        $order = Order::where('payment_token', $token)->first();

        if (!$order) return null;
        if ($this->isExpired($order)) return null;

        return $order;
    }

    private function isExpired(Order $order): bool
    {
        return $order->payment_token_expires_at
            && $order->payment_token_expires_at->isPast();
    }

    private function mpesaConfig(): ?array
    {
        $raw = DB::table('settings')->where('key', 'mpesa_config')->value('value');
        if (!$raw) {
            // Fall back to .env / config/services.php
            $key    = config('services.mpesa.consumer_key');
            $secret = config('services.mpesa.consumer_secret');
            if (!$key) return null;
            return [
                'consumer_key'    => $key,
                'consumer_secret' => $secret,
                'passkey'         => config('services.mpesa.passkey'),
                'shortcode'       => config('services.mpesa.shortcode'),
                'environment'     => config('services.mpesa.environment', 'sandbox'),
            ];
        }
        return json_decode($raw, true);
    }

    private function paystackConfig(): ?array
    {
        // Primary: individual settings rows (written by PaymentMethodController)
        $secretKey = DB::table('settings')->where('key', 'paystack_secret_key')->value('value');
        if (!empty($secretKey)) {
            return ['secret_key' => $secretKey];
        }

        // Legacy: single JSON blob
        $raw = DB::table('settings')->where('key', 'paystack_config')->value('value');
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !empty($decoded['secret_key'])) return $decoded;
        }

        // Final fallback: .env
        $key = config('services.paystack.secret_key');
        if (!$key) return null;
        return ['secret_key' => $key];
    }
}
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\MpesaService;
use App\Services\NotificationService;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    // =========================================================================
    // POST /orders/{id}/payment  (customer or admin)
    // Initiate a payment for an order via the requested gateway.
    // =========================================================================

    public function initiatePayment(Request $request, $id)
    {
        // Normalise aliases sent by different frontends:
        //   'paystack'    -> 'card_paystack'   (OrderDetailPage sends the DB code directly)
        //   'flutterwave' -> 'card_flutterwave'
        $aliases = ['paystack' => 'card_paystack', 'flutterwave' => 'card_flutterwave'];
        if (isset($aliases[$request->input('payment_method')])) {
            $request->merge(['payment_method' => $aliases[$request->input('payment_method')]]);
        }

        $validated = $request->validate([
            'payment_method' => 'required|in:mpesa,card_paystack,card_flutterwave',
            'phone'          => 'required_if:payment_method,mpesa|string',
            'email'          => 'required_if:payment_method,card_paystack,card_flutterwave|email',
        ]);

        $order = Order::findOrFail($id);

        if ($order->payment_status === 'paid') {
            return response()->json(['message' => 'Order already paid'], 422);
        }

        return match ($validated['payment_method']) {
            'mpesa'           => $this->initiateMpesaPayment($order, $validated['phone']),
            'card_paystack'   => $this->initiatePaystackPayment($order, $validated['email']),
            'card_flutterwave'=> $this->initiateFlutterwavePayment($order, $validated['email']),
            default           => response()->json(['message' => 'Invalid payment method'], 422),
        };
    }

    // =========================================================================
    // POST /admin/orders/{orderId}/payments/{paymentId}/verify-mpesa
    //
    // Staff enters an offline M-Pesa transaction code (e.g. QJL3ABC7DE) and
    // the system queries Daraja to confirm before marking the payment as paid.
    //
    // Flow:
    //   1. If the Payment has a provider_reference (CheckoutRequestID), query
    //      the STK status endpoint.
    //   2. If staff provide a transaction_code (M-Pesa receipt), call the
    //      Transaction Status API.
    //   3. On confirmed success, mark payment paid + sync order payment_status.
    // =========================================================================

    public function verifyMpesa(Request $request, int $orderId, int $paymentId)
    {
        $validated = $request->validate([
            // Receipt number the customer shows (e.g. QJL3ABC7DE)
            'transaction_code' => 'nullable|string|max:20',
        ]);

        $order   = Order::findOrFail($orderId);
        $payment = Payment::where('order_id', $orderId)->where('id', $paymentId)->firstOrFail();

        if ($payment->status === 'paid') {
            return response()->json(['message' => 'Payment is already confirmed as paid.'], 422);
        }

        try {
            $mpesa = new MpesaService();
            $confirmed = false;
            $receipt   = null;
            $darajaResponse = [];

            // Strategy 1: query STK status using the stored CheckoutRequestID
            if ($payment->provider_reference && !str_starts_with($payment->provider_reference, 'QJ')) {
                try {
                    $result         = $mpesa->querySTK($payment->provider_reference);
                    $darajaResponse = $result;
                    // ResultCode '0' means the STK was completed successfully
                    if (($result['ResultCode'] ?? '') === '0' || ($result['ResultCode'] ?? '') === 0) {
                        $confirmed = true;
                    }
                } catch (\Exception $e) {
                    Log::warning('STK query failed, falling through to transaction code', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Strategy 2: if staff provided a receipt number, use Transaction Status API
            if (!$confirmed && !empty($validated['transaction_code'])) {
                try {
                    $receipt    = strtoupper(trim($validated['transaction_code']));
                    $resultUrl  = url('/api/v1/webhooks/mpesa/transaction-result');
                    $queueUrl   = url('/api/v1/webhooks/mpesa/transaction-timeout');
                    $result     = $mpesa->transactionStatus($receipt, $resultUrl, $queueUrl);
                    $darajaResponse = $result;

                    // Daraja returns '0' for accepted queries; the actual result
                    // comes async via the resultUrl. For now accept if response is good.
                    if (($result['ResponseCode'] ?? '') === '0') {
                        $confirmed = true;
                        // Update the provider_reference to the actual receipt number
                        $payment->update(['provider_reference' => $receipt]);
                    }
                } catch (\Exception $e) {
                    Log::warning('Transaction status query failed', ['error' => $e->getMessage()]);
                }
            }

            if (!$confirmed) {
                return response()->json([
                    'message' => 'Could not confirm payment with Daraja. Please check the transaction code and try again.',
                    'daraja_response' => $darajaResponse,
                ], 422);
            }

            // ── Mark payment as paid and sync order ───────────────────────────
            DB::transaction(function () use ($payment, $order, $receipt) {
                $payment->update([
                    'status'             => 'paid',
                    'paid_at'            => now(),
                    'provider_reference' => $receipt ?? $payment->provider_reference,
                ]);

                $order->refresh();
                $order->syncPaymentStatus();
                $order->refresh();

                if ($order->payment_status === 'paid' && $order->status === 'pending') {
                    $order->update(['status' => 'processing']);
                }
            });

            // ── Notifications & audit ─────────────────────────────────────────
            NotificationService::paymentReceived(
                $payment->id,
                $payment->payment_number,
                $order->id,
                $order->order_number,
                (float) $payment->amount,
                $order->currency_code,
                'mpesa'
            );

            ActivityLogService::log('mpesa_payment_verified', $order, [
                'payment_id'       => $payment->id,
                'payment_number'   => $payment->payment_number,
                'transaction_code' => $receipt ?? $payment->provider_reference,
                'amount'           => $payment->amount,
            ]);

            return response()->json([
                'message'        => 'M-Pesa payment confirmed successfully.',
                'payment_status' => $order->fresh()->payment_status,
                'payment'        => $payment->fresh(),
            ]);

        } catch (\Exception $e) {
            Log::error('verifyMpesa error', [
                'order_id'   => $orderId,
                'payment_id' => $paymentId,
                'error'      => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Verification failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    // POST /admin/orders/{orderId}/payments/{paymentId}/verify-paystack
    // Verify a Paystack payment directly via the Paystack API using the
    // reference code — no webhook required.
    // =========================================================================

    public function verifyPaystack(Request $request, int $orderId, int $paymentId)
    {
        $validated = $request->validate([
            'reference' => 'required|string|max:100',
        ]);

        $order   = Order::findOrFail($orderId);
        $payment = Payment::where('order_id', $orderId)->where('id', $paymentId)->firstOrFail();

        if ($payment->status === 'paid') {
            return response()->json([
                'message'        => 'Payment is already confirmed as paid.',
                'payment_status' => $order->payment_status,
                'payment'        => $payment,
            ]);
        }

        try {
            $secretKey = $this->paystackSecretKey();
            $reference = trim($validated['reference']);

            $response = Http::withToken($secretKey)
                ->timeout(15)
                ->get("https://api.paystack.co/transaction/verify/{$reference}");

            if (!$response->successful()) {
                return response()->json([
                    'message' => 'Could not reach Paystack. Check your connection and try again.',
                ], 422);
            }

            $data   = $response->json()['data'] ?? [];
            $status = $data['status'] ?? '';

            if ($status !== 'success') {
                return response()->json([
                    'message' => "Paystack reports this transaction as '{$status}'. Only successful payments can be confirmed.",
                ], 422);
            }

            DB::transaction(function () use ($payment, $order, $reference) {
                $payment->update([
                    'status'             => 'paid',
                    'provider_reference' => $reference,
                    'paid_at'            => now(),
                ]);

                $order->refresh();
                $order->syncPaymentStatus();
                $order->refresh();

                if ($order->payment_status === 'paid' && $order->status === 'pending') {
                    $order->update(['status' => 'processing']);
                }
            });

            try {
                NotificationService::paymentReceived(
                    $payment->id,
                    $payment->payment_number,
                    $order->id,
                    $order->order_number,
                    (float) $payment->amount,
                    $order->currency_code,
                    'card_paystack'
                );
                ActivityLogService::log('paystack_payment_verified', $order, [
                    'payment_id'  => $payment->id,
                    'reference'   => $reference,
                    'amount'      => $payment->amount,
                    'verified_by' => 'admin_manual',
                ]);
            } catch (\Exception) {}

            return response()->json([
                'message'        => 'Paystack payment confirmed successfully.',
                'payment_status' => $order->fresh()->payment_status,
                'payment'        => $payment->fresh(),
            ]);

        } catch (\Exception $e) {
            Log::error('verifyPaystack error', [
                'order_id'   => $orderId,
                'payment_id' => $paymentId,
                'error'      => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Verification failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    // M-Pesa STK Push - internal
    // Phase 3: uses MpesaService (DB-driven credentials) instead of
    // hardcoded config() calls.
    // =========================================================================

    protected function initiateMpesaPayment(Order $order, string $phone)
    {
        try {
            $mpesa = new MpesaService();

            // Use a per-order callback URL so the order is identifiable
            // even without a DB lookup on the CheckoutRequestID
            $callbackUrl = $order->payment_token
                ? url("/api/v1/pay/{$order->payment_token}/mpesa-callback")
                : url('/api/v1/webhooks/mpesa/callback');

            $result = $mpesa->stkPush(
                phone:       $phone,
                amount:      (int) ceil((float) $order->total_amount),
                reference:   $order->order_number,
                callbackUrl: $callbackUrl,
                description: "Order #{$order->order_number}"
            );

            // Record pending payment
            $payment = Payment::create([
                'order_id'           => $order->id,
                'payment_method'     => 'mpesa',
                'amount'             => $order->total_amount,
                'currency_code'      => $order->currency_code,
                'status'             => 'pending',
                'provider_reference' => $result['CheckoutRequestID'],
                'phone_number'       => $mpesa->normalisePhone($phone),
                'tax_inclusive'      => $order->prices_include_tax ?? true,
            ]);

            ActivityLogService::log('mpesa_stk_initiated', $order, [
                'payment_id'          => $payment->id,
                'checkout_request_id' => $result['CheckoutRequestID'],
                'phone'               => $mpesa->normalisePhone($phone),
                'amount'              => $order->total_amount,
            ]);

            return response()->json([
                'message'             => 'M-Pesa payment initiated. Check your phone to complete payment.',
                'checkout_request_id' => $result['CheckoutRequestID'],
                'payment_id'          => $payment->id,
            ]);

        } catch (\Exception $e) {
            Log::error('M-Pesa STK Push error', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Failed to initiate M-Pesa payment. ' . $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    // Paystack initiation
    // Phase 3: added notification + audit dispatch on webhook confirm.
    // =========================================================================

    protected function initiatePaystackPayment(Order $order, string $email)
    {
        try {
            $secretKey   = $this->paystackSecretKey();
            // Callback must point to the React frontend, not the Laravel backend.
            // Uses FRONTEND_URL env var, falling back to APP_URL.
            $frontendUrl = rtrim(env('FRONTEND_URL', config('app.url')), '/');
            $callbackUrl = $order->payment_token
                ? "{$frontendUrl}/pay/{$order->payment_token}?status=returned"
                : config('services.paystack.callback_url', $frontendUrl);

            $response = Http::withToken($secretKey)
                ->timeout(15)
                ->post('https://api.paystack.co/transaction/initialize', [
                    'email'        => $email,
                    'amount'       => (int) round((float) $order->total_amount * 100),
                    'currency'     => $order->currency_code,
                    'reference'    => $order->order_number . '-' . time(),
                    'callback_url' => $callbackUrl,
                    'metadata'     => [
                        'order_id'      => $order->id,
                        'order_number'  => $order->order_number,
                        'payment_token' => $order->payment_token,
                    ],
                ]);

            if (!$response->successful()) {
                throw new \Exception('Paystack initialization failed: ' . $response->body());
            }

            $data = $response->json()['data'];

            $payment = Payment::create([
                'order_id'           => $order->id,
                'payment_method'     => 'card_paystack',
                'amount'             => $order->total_amount,
                'currency_code'      => $order->currency_code,
                'status'             => 'pending',
                'provider_reference' => $data['reference'],
                'tax_inclusive'      => $order->prices_include_tax ?? true,
            ]);

            ActivityLogService::log('paystack_initiated', $order, [
                'payment_id' => $payment->id,
                'reference'  => $data['reference'],
            ]);

            return response()->json([
                'message'           => 'Payment initialized',
                'authorization_url' => $data['authorization_url'],
                'access_code'       => $data['access_code'],
                'reference'         => $data['reference'],
                'payment_id'        => $payment->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Paystack payment error', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to initiate payment. ' . $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // Flutterwave initiation
    // =========================================================================

    protected function initiateFlutterwavePayment(Order $order, string $email)
    {
        try {
            $secretKey   = config('services.flutterwave.secret_key');
            $redirectUrl = $order->payment_token
                ? url("/pay/{$order->payment_token}?status=returned")
                : config('services.flutterwave.redirect_url');

            $response = Http::withToken($secretKey)
                ->timeout(15)
                ->post('https://api.flutterwave.com/v3/payments', [
                    'tx_ref'          => $order->order_number . '-' . time(),
                    'amount'          => (float) $order->total_amount,
                    'currency'        => $order->currency_code,
                    'redirect_url'    => $redirectUrl,
                    'payment_options' => 'card',
                    'customer'        => [
                        'email' => $email,
                        'name'  => $order->customer_first_name . ' ' . $order->customer_last_name,
                    ],
                    'customizations'  => [
                        'title'       => config('app.name', 'Bethany House'),
                        'description' => "Payment for Order #{$order->order_number}",
                    ],
                ]);

            if (!$response->successful()) {
                throw new \Exception('Flutterwave initialization failed: ' . $response->body());
            }

            $data = $response->json()['data'];

            $payment = Payment::create([
                'order_id'           => $order->id,
                'payment_method'     => 'card_flutterwave',
                'amount'             => $order->total_amount,
                'currency_code'      => $order->currency_code,
                'status'             => 'pending',
                'provider_reference' => $data['tx_ref'] ?? $order->order_number,
                'tax_inclusive'      => $order->prices_include_tax ?? true,
            ]);

            return response()->json([
                'message'      => 'Payment initialized',
                'payment_link' => $data['link'],
                'payment_id'   => $payment->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Flutterwave payment error', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to initiate payment. ' . $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // Webhook handlers
    // Phase 3: all three handlers now dispatch notifications + audit logs.
    // =========================================================================

    /**
     * POST /webhooks/mpesa/callback
     * Handles Daraja STK push callbacks (admin-initiated from admin panel).
     */
    public function mpesaCallback(Request $request)
    {
        $data = $request->all();
        Log::info('M-Pesa Callback received', $data);

        try {
            $resultCode        = $data['Body']['stkCallback']['ResultCode'] ?? null;
            $checkoutRequestId = $data['Body']['stkCallback']['CheckoutRequestID'] ?? null;

            if (!$checkoutRequestId) {
                return response()->json(['message' => 'Invalid callback data'], 400);
            }

            $payment = Payment::where('provider_reference', $checkoutRequestId)->first();

            if (!$payment) {
                Log::warning('mpesaCallback: payment not found', [
                    'checkout_request_id' => $checkoutRequestId,
                ]);
                return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
            }

            if ($resultCode === 0) {
                $meta           = $data['Body']['stkCallback']['CallbackMetadata']['Item'] ?? [];
                $receipt        = collect($meta)->firstWhere('Name', 'MpesaReceiptNumber')['Value'] ?? null;

                DB::transaction(function () use ($payment, $receipt, $data) {
                    $payment->update([
                        'status'             => 'paid',
                        'provider_reference' => $receipt ?? $payment->provider_reference,
                        'paid_at'            => now(),
                    ]);

                    $order = $payment->order;
                    $order->refresh();
                    $order->syncPaymentStatus();
                    $order->refresh();

                    if ($order->payment_status === 'paid' && $order->status === 'pending') {
                        $order->update(['status' => 'processing']);
                    }
                });

                $order = $payment->fresh()->order;

                // ── Notifications & audit ─────────────────────────────────────
                NotificationService::paymentReceived(
                    $payment->id,
                    $payment->payment_number,
                    $order->id,
                    $order->order_number,
                    (float) $payment->amount,
                    $order->currency_code,
                    'mpesa'
                );

                ActivityLogService::log('mpesa_payment_confirmed', $order, [
                    'payment_id'   => $payment->id,
                    'receipt'      => $receipt,
                    'amount'       => $payment->amount,
                    'via'          => 'daraja_callback',
                ]);

            } else {
                $payment->update(['status' => 'failed']);

                ActivityLogService::log('mpesa_payment_failed', $payment->order, [
                    'payment_id'  => $payment->id,
                    'result_code' => $resultCode,
                    'result_desc' => $data['Body']['stkCallback']['ResultDesc'] ?? 'Unknown',
                ]);
            }

            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

        } catch (\Exception $e) {
            Log::error('M-Pesa callback error', ['error' => $e->getMessage(), 'data' => $data]);
            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        }
    }

    /**
     * POST /webhooks/mpesa/validation
     */
    public function mpesaValidation(Request $request)
    {
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    /**
     * POST /webhooks/paystack/webhook
     * Phase 3: dispatches notifications + audit log on charge.success.
     */
    public function paystackWebhook(Request $request)
    {
        $signature        = $request->header('x-paystack-signature');
        $secretKey        = $this->paystackSecretKey();
        $computedSignature = hash_hmac('sha512', $request->getContent(), $secretKey);

        if ($signature !== $computedSignature) {
            Log::warning('Invalid Paystack webhook signature');
            return response()->json(['message' => 'Invalid signature'], 400);
        }

        $event = $request->input('event');
        $data  = $request->input('data');
        Log::info('Paystack webhook', ['event' => $event]);

        try {
            if ($event === 'charge.success') {
                $reference = $data['reference'];
                $payment   = Payment::where('provider_reference', $reference)->first();

                if (!$payment) {
                    Log::warning('Paystack webhook: payment not found', ['reference' => $reference]);
                    return response()->json(['message' => 'OK']);
                }

                DB::transaction(function () use ($payment, $data) {
                    $payment->update([
                        'status'  => 'paid',
                        'paid_at' => now(),
                    ]);

                    $order = $payment->order;
                    $order->refresh();
                    $order->syncPaymentStatus();
                    $order->refresh();

                    if ($order->payment_status === 'paid' && $order->status === 'pending') {
                        $order->update(['status' => 'processing']);
                    }
                });

                $order = $payment->fresh()->order;

                NotificationService::paymentReceived(
                    $payment->id,
                    $payment->payment_number,
                    $order->id,
                    $order->order_number,
                    (float) $payment->amount,
                    $order->currency_code,
                    'card_paystack'
                );

                ActivityLogService::log('paystack_payment_confirmed', $order, [
                    'payment_id' => $payment->id,
                    'reference'  => $reference,
                    'amount'     => $payment->amount,
                ]);
            }

            return response()->json(['message' => 'Webhook processed']);

        } catch (\Exception $e) {
            Log::error('Paystack webhook error', ['error' => $e->getMessage(), 'event' => $event]);
            return response()->json(['message' => 'Webhook processing failed'], 500);
        }
    }

    /**
     * POST /webhooks/flutterwave/webhook
     * Phase 3: dispatches notifications + audit log on charge.completed.
     */
    public function flutterwaveWebhook(Request $request)
    {
        $secretHash = config('services.flutterwave.secret_hash');
        $signature  = $request->header('verif-hash');

        if ($signature !== $secretHash) {
            Log::warning('Invalid Flutterwave webhook signature');
            return response()->json(['message' => 'Invalid signature'], 400);
        }

        $data = $request->all();
        Log::info('Flutterwave webhook received', ['event' => $data['event'] ?? null]);

        try {
            if (($data['event'] ?? '') === 'charge.completed' &&
                ($data['data']['status'] ?? '') === 'successful') {

                $txRef   = $data['data']['tx_ref'];
                $flwRef  = $data['data']['flw_ref'];
                $payment = Payment::where('provider_reference', 'LIKE', "%{$txRef}%")->first();

                if (!$payment) {
                    Log::warning('Flutterwave webhook: payment not found', ['tx_ref' => $txRef]);
                    return response()->json(['message' => 'OK']);
                }

                DB::transaction(function () use ($payment, $flwRef) {
                    $payment->update([
                        'status'             => 'paid',
                        'provider_reference' => $flwRef,
                        'paid_at'            => now(),
                    ]);

                    $order = $payment->order;
                    $order->refresh();
                    $order->syncPaymentStatus();
                    $order->refresh();

                    if ($order->payment_status === 'paid' && $order->status === 'pending') {
                        $order->update(['status' => 'processing']);
                    }
                });

                $order = $payment->fresh()->order;

                NotificationService::paymentReceived(
                    $payment->id,
                    $payment->payment_number,
                    $order->id,
                    $order->order_number,
                    (float) $payment->amount,
                    $order->currency_code,
                    'card_flutterwave'
                );

                ActivityLogService::log('flutterwave_payment_confirmed', $order, [
                    'payment_id' => $payment->id,
                    'flw_ref'    => $flwRef,
                    'amount'     => $payment->amount,
                ]);
            }

            return response()->json(['message' => 'Webhook processed']);

        } catch (\Exception $e) {
            Log::error('Flutterwave webhook error', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Webhook processing failed'], 500);
        }
    }

    // =========================================================================
    // Transaction list (admin)
    // =========================================================================

    public function allTransactions(Request $request)
    {
        $query = Payment::with(['order:id,order_number,customer_first_name,customer_last_name,currency_code'])
            ->orderBy('payments.created_at', 'desc');

        if ($request->filled('status'))            $query->where('status', $request->status);
        if ($request->filled('payment_method'))    $query->where('payment_method', $request->payment_method);
        if ($request->filled('currency_code'))     $query->where('currency_code', $request->currency_code);
        if ($request->filled('requires_approval')) $query->where('requires_approval', (bool) $request->requires_approval);
        if ($request->filled('start_date'))        $query->whereDate('payments.created_at', '>=', $request->start_date);
        if ($request->filled('end_date'))          $query->whereDate('payments.created_at', '<=', $request->end_date);
        if ($request->filled('min_amount'))        $query->where('amount', '>=', $request->min_amount);
        if ($request->filled('max_amount'))        $query->where('amount', '<=', $request->max_amount);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('payment_number',      'ILIKE', "%{$s}%")
                  ->orWhere('provider_reference', 'ILIKE', "%{$s}%")
                  ->orWhereHas('order', fn ($q2) => $q2->where('order_number',        'ILIKE', "%{$s}%")
                      ->orWhere('customer_first_name', 'ILIKE', "%{$s}%")
                      ->orWhere('customer_last_name',  'ILIKE', "%{$s}%")
                  );
            });
        }

        $perPage      = min((int) $request->get('per_page', 20), 100);
        $transactions = $query->paginate($perPage);

        return response()->json($transactions);
    }

    /**
     * GET /admin/payment-transactions/analytics
     *
     * Aggregate KPIs, daily trend, and breakdown by payment method
     * for the Payment Transactions analytics panel.
     */
    public function transactionAnalytics(Request $request)
    {
        $start    = $request->get('start_date', now()->startOfMonth()->toDateString());
        $end      = $request->get('end_date',   now()->toDateString());
        $currency = $request->get('currency_code');

        $base = DB::table('payments')
            ->whereDate('created_at', '>=', $start)
            ->whereDate('created_at', '<=', $end)
            ->when($currency, fn ($q) => $q->where('currency_code', strtoupper($currency)));

        // Top-level KPIs — single query
        $kpis = (clone $base)->selectRaw("
            COUNT(*)                                                              AS total_count,
            COALESCE(SUM(amount), 0)                                             AS total_volume,
            COUNT(CASE WHEN status = 'paid'    THEN 1 END)                       AS paid_count,
            COALESCE(SUM(CASE WHEN status = 'paid'   THEN amount END), 0)        AS paid_volume,
            COUNT(CASE WHEN status = 'failed'  THEN 1 END)                       AS failed_count,
            COUNT(CASE WHEN status = 'pending' THEN 1 END)                       AS pending_count,
            COUNT(CASE WHEN refund_amount > 0  THEN 1 END)                       AS refunded_count,
            COALESCE(SUM(CASE WHEN refund_amount > 0 THEN refund_amount END), 0) AS refunded_volume,
            COALESCE(AVG(CASE WHEN status = 'paid' THEN amount END), 0)          AS avg_transaction
        ")->first();

        $successRate = $kpis->total_count > 0
            ? round(($kpis->paid_count / $kpis->total_count) * 100, 1)
            : 0;

        // Volume by payment method
        $byMethod = (clone $base)
            ->selectRaw("
                payment_method,
                COUNT(*) AS count,
                COALESCE(SUM(CASE WHEN status = 'paid' THEN amount END), 0) AS volume
            ")
            ->groupBy('payment_method')
            ->orderByDesc('volume')
            ->get();

        // Daily trend
        $daily = (clone $base)
            ->selectRaw("
                DATE(created_at) AS date,
                COUNT(*) AS count,
                COALESCE(SUM(CASE WHEN status = 'paid'   THEN amount END), 0) AS volume,
                COUNT(CASE WHEN status = 'failed' THEN 1 END)                 AS failed
            ")
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json([
            'period'          => ['start' => $start, 'end' => $end],
            'total_volume'    => (float) $kpis->total_volume,
            'total_count'     => (int)   $kpis->total_count,
            'paid_volume'     => (float) $kpis->paid_volume,
            'paid_count'      => (int)   $kpis->paid_count,
            'failed_count'    => (int)   $kpis->failed_count,
            'pending_count'   => (int)   $kpis->pending_count,
            'refunded_volume' => (float) $kpis->refunded_volume,
            'refunded_count'  => (int)   $kpis->refunded_count,
            'avg_transaction' => (float) $kpis->avg_transaction,
            'success_rate'    => $successRate,
            'by_method'       => $byMethod,
            'daily'           => $daily,
        ]);
    }

    public function exportTransactions(Request $request)
    {
        $query = Payment::with(['order:id,order_number'])
            ->orderBy('created_at', 'desc')
            ->limit(5000);

        if ($request->filled('start_date')) $query->whereDate('created_at', '>=', $request->start_date);
        if ($request->filled('end_date'))   $query->whereDate('created_at', '<=', $request->end_date);

        return response()->json(['data' => $query->get()]);
    }

    public function transactionDetails($id)
    {
        $payment = Payment::with(['order', 'approvedBy:id,first_name,last_name'])->findOrFail($id);
        return response()->json(['payment' => $payment]);
    }

    public function refundTransaction(Request $request, $id)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:500',
        ]);

        $payment = Payment::with('order')->findOrFail($id);

        if (!$payment->isPaid()) {
            return response()->json(['message' => 'Only paid transactions can be refunded.'], 422);
        }

        // Never refund more than is still refundable on THIS payment. refund_amount
        // must ACCUMULATE (not overwrite) — Order::totalPaid() = SUM(amount -
        // refund_amount), so overwriting it lets repeat refunds under-count and an
        // uncapped amount drives the order's net paid negative.
        $lineRefundable = (float) $payment->amount - (float) $payment->refund_amount;
        if ((float) $validated['amount'] > $lineRefundable + 0.01) {
            return response()->json([
                'message'    => 'Refund exceeds the amount still refundable on this payment.',
                'refundable' => round(max(0, $lineRefundable), 2),
            ], 422);
        }

        // TODO: call gateway refund API based on payment_method (and reverse the
        // cash drawer for cash payments — tracked as a follow-up; needs the POS
        // drawer-resolution helpers extracted into a shared service).
        DB::transaction(function () use ($payment, $validated) {
            $payment->update([
                'refund_amount' => (float) $payment->refund_amount + (float) $validated['amount'],
                'refunded_at'   => now(),
            ]);
            // Reconcile the order's payment_status against the now-reduced net.
            $payment->order?->syncPaymentStatus();
        });

        ActivityLogService::log('payment_refunded', $payment->order, [
            'payment_id' => $payment->id,
            'amount'     => $validated['amount'],
            'reason'     => $validated['reason'],
        ]);

        return response()->json(['message' => 'Refund recorded.', 'payment' => $payment->fresh()]);
    }

    // =========================================================================
    // Admin — Void & Reassign
    // =========================================================================

    /**
     * POST /payment-transactions/{id}/void
     *
     * Marks a payment as voided, records who did it and why, and resets the
     * order's paid/balance state so it reflects the removed payment.
     */
    public function voidPayment(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $payment = \App\Models\Payment::with('order')->findOrFail($id);

        if ($payment->status === 'voided') {
            return response()->json(['message' => 'Payment is already voided.'], 422);
        }

        $oldStatus   = $payment->status;
        $oldOrderId  = $payment->order_id;

        $payment->update([
            'status'     => 'voided',
            'void_reason' => $validated['reason'],
            'voided_at'  => now(),
            'voided_by'  => auth()->id(),
        ]);

        // Recompute the order's payment state. syncPaymentStatus() is the
        // authoritative source (it drives payment_status from the net of the
        // remaining paid payments); the amount_paid/balance_due columns are kept
        // in sync with the SAME net figure so the two don't diverge.
        if ($payment->order) {
            $order = $payment->order;
            $netPaid = $order->totalPaid();   // SUM(amount - refund_amount) over paid
            $order->update([
                'amount_paid' => $netPaid,
                'balance_due' => max(0, (float) $order->total_amount - $netPaid),
            ]);
            $order->syncPaymentStatus();
        }

        ActivityLogService::log('payment_voided', $payment, [
            'payment_number' => $payment->payment_number,
            'amount'         => $payment->amount,
            'order_id'       => $oldOrderId,
            'previous_status'=> $oldStatus,
            'reason'         => $validated['reason'],
            'voided_by'      => auth()->id(),
        ]);

        return response()->json([
            'message' => 'Payment voided successfully.',
            'payment' => $payment->fresh(),
        ]);
    }

    /**
     * POST /payment-transactions/{id}/reassign
     *
     * Moves a payment from its current order to a different order, then
     * recomputes the balance on both the old and the new order.
     */
    public function reassignPayment(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'order_id' => 'required|integer|exists:orders,id',
            'reason'   => 'required|string|max:1000',
        ]);

        $payment = \App\Models\Payment::with('order')->findOrFail($id);

        if ($payment->status === 'voided') {
            return response()->json(['message' => 'Voided payments cannot be reassigned.'], 422);
        }

        $newOrderId = (int) $validated['order_id'];

        if ($payment->order_id === $newOrderId) {
            return response()->json(['message' => 'Payment is already assigned to that order.'], 422);
        }

        $oldOrderId = $payment->order_id;

        $payment->update(['order_id' => $newOrderId]);

        // Recompute balances on both orders
        foreach (array_filter([$oldOrderId, $newOrderId]) as $oid) {
            $order = \App\Models\Order::find($oid);
            if (!$order) continue;
            $totalPaid = \App\Models\Payment::where('order_id', $oid)
                ->where('status', 'paid')
                ->sum('amount');
            $order->update([
                'amount_paid' => $totalPaid,
                'balance_due' => max(0, $order->total_amount - $totalPaid),
            ]);
        }

        ActivityLogService::log('payment_reassigned', $payment, [
            'payment_number' => $payment->payment_number,
            'amount'         => $payment->amount,
            'from_order_id'  => $oldOrderId,
            'to_order_id'    => $newOrderId,
            'reason'         => $validated['reason'],
            'reassigned_by'  => auth()->id(),
        ]);

        return response()->json([
            'message' => 'Payment reassigned successfully.',
            'payment' => $payment->fresh()->load('order'),
        ]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Load Paystack secret key from settings table or fall back to config.
     *
     * Checks individual rows first (paystack_secret_key) — written by
     * PaymentMethodController::updateConfig() — then falls back to the
     * legacy paystack_config JSON blob, then to .env.
     */
    private function paystackSecretKey(): string
    {
        // Primary: individual settings row (written by PaymentMethodController)
        $key = DB::table('settings')->where('key', 'paystack_secret_key')->value('value');
        if (!empty($key)) {
            return $key;
        }

        // Legacy: single JSON blob stored under 'paystack_config'
        $raw = DB::table('settings')->where('key', 'paystack_config')->value('value');
        if ($raw) {
            $config = json_decode($raw, true);
            if (is_array($config) && !empty($config['secret_key'])) {
                return $config['secret_key'];
            }
        }

        // Final fallback: .env / config/services.php
        return config('services.paystack.secret_key', '');
    }
}
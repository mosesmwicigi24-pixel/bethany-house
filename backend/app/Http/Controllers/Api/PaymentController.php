<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\MpesaService;
use App\Services\NotificationService;
use App\Services\ActivityLogService;
use App\Services\ReceiptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Support\ServerError;

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

        // No STK push, card or Flutterwave session for a dead order (Order::isPayable).
        if ($refusal = \App\Support\PaymentGate::refuseStaff($order)) {
            return $refusal;
        }

        if ($order->payment_status === 'paid') {
            return response()->json(['message' => 'Order already paid'], 422);
        }

        // M-Pesa moves shillings. Pushing a USD order's total to it asked the
        // customer for KES 100 on a USD 100 order.
        if ($validated['payment_method'] === 'mpesa' && strtoupper((string) $order->currency_code) !== 'KES') {
            return response()->json(['message' => "M-Pesa takes shillings; this order is in {$order->currency_code}. Use card or bank transfer."], 422);
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
    // Staff confirm an M-Pesa payment with Safaricom before it counts as paid.
    //
    //   1. STK Push Query on the payment's CheckoutRequestID. ResultCode 0 is
    //      Safaricom saying that push was completed — a real confirmation, so
    //      the payment is settled at once.
    //   2. A receipt code the customer shows (e.g. QJL3ABC7DE): a Transaction
    //      Status query. Daraja's synchronous ResponseCode '0' only means
    //      "query accepted" — the answer comes later, to
    //      /webhooks/mpesa/transaction-result. Until 2026-10-26 the button
    //      settled the payment on that acceptance, so almost any receipt code
    //      Daraja accepted recorded money that may never have arrived. Now the
    //      request is recorded on the payment (provider_response
    //      .mpesa_verification), staff get 202 "asked Safaricom", and only
    //      Safaricom's answer can settle it (mpesaTransactionResult).
    // =========================================================================

    /** provider_response key for a receipt-code verification in flight. */
    private const MPESA_VERIFICATION = 'mpesa_verification';

    /**
     * Verification states that still wait for Safaricom's answer. 'unmatched'
     * stays open: an answer tied only by its receipt is never applied, and
     * must not stop the real answer from settling the payment.
     */
    private const MPESA_VERIFICATION_OPEN = ['pending', 'timed_out', 'unmatched'];

    /** Payment states a Safaricom answer must never settle. */
    private const MPESA_CLOSED_PAYMENT = ['voided', 'refunded', 'cancelled'];

    /**
     * Issue a receipt + commit reserved stock for a settled payment, when the
     * order is a quote-originated invoice. No-op for POS / plain online orders.
     * Best-effort (its own savepoint + catch) so a receipt hiccup can never roll
     * back a real gateway settlement.
     */
    private function settleReceipt(\App\Models\Order $order, \App\Models\Payment $payment): void
    {
        try {
            ReceiptService::onPaymentSettled($order, $payment);
        } catch (\Throwable $e) {
            Log::warning('Receipt/commit after gateway settlement failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Mark an M-Pesa payment paid that Safaricom has CONFIRMED, and sync its
     * order — the one settle path for both verify strategies. Call inside a DB
     * transaction; announceMpesaSettlement() after it commits. A payment on a
     * dead order is recorded and flagged by the Payment model's own hook
     * (LatePayment), never revives the order.
     */
    private function applyMpesaSettlement(Payment $payment, Order $order, ?string $receipt, array $extra = []): void
    {
        $payment->update([
            'status'             => 'paid',
            'paid_at'            => now(),
            'provider_reference' => $receipt ?? $payment->provider_reference,
        ] + $extra);

        $order->refresh();
        $order->syncPaymentStatus();
        $order->refresh();

        // Quote-originated invoice → issue receipt + commit stock (no-op otherwise).
        $this->settleReceipt($order, $payment);

        if ($order->payment_status === 'paid' && $order->status === 'pending') {
            $order->update(['status' => 'processing']);
        }
    }

    /** Finance's bell + the audit entry for a confirmed M-Pesa payment. */
    private function announceMpesaSettlement(Payment $payment, Order $order, ?string $receipt, string $via, array $extra = []): void
    {
        $payment->refresh();
        $order->refresh();

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
            'via'              => $via,
        ] + $extra);
    }

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
            $darajaResponse = [];

            // Strategy 1: query STK status using the stored CheckoutRequestID.
            // ResultCode 0 is Safaricom confirming the push was paid.
            if ($payment->provider_reference && !str_starts_with($payment->provider_reference, 'QJ')) {
                try {
                    $result         = $mpesa->querySTK($payment->provider_reference);
                    $darajaResponse = $result;
                    if (($result['ResultCode'] ?? '') === '0' || ($result['ResultCode'] ?? '') === 0) {
                        DB::transaction(fn () => $this->applyMpesaSettlement($payment, $order, null));
                        $this->announceMpesaSettlement($payment, $order, null, 'stk_query');

                        return response()->json([
                            'message'        => $order->fresh()->isPayable()
                                ? 'M-Pesa payment confirmed successfully.'
                                : \App\Support\PaymentGate::lateMessage($order->fresh()),
                            'payment_status' => $order->fresh()->payment_status,
                            'payment'        => $payment->fresh(),
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::warning('STK query failed, falling through to transaction code', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Strategy 2: a receipt number — ask Safaricom, and wait for its answer.
            if (!empty($validated['transaction_code'])) {
                $receipt = strtoupper(trim($validated['transaction_code']));

                if ($taken = $this->paymentHoldingReceipt($receipt, $payment->id)) {
                    return response()->json([
                        'message' => $this->receiptTakenMessage($receipt, $taken),
                        'reason'  => 'receipt_already_recorded',
                    ], 422);
                }

                try {
                    $result = $mpesa->transactionStatus(
                        $receipt,
                        url('/api/webhooks/mpesa/transaction-result'),
                        url('/api/webhooks/mpesa/transaction-timeout'),
                    );
                    $darajaResponse = $result;
                    $conversation   = trim((string) ($result['ConversationID'] ?? ''));
                    $originator     = trim((string) ($result['OriginatorConversationID'] ?? ''));

                    // ResponseCode '0' = Safaricom accepted the QUESTION. Not
                    // proof of money: the answer arrives at transaction-result,
                    // matched by these conversation ids.
                    if ((string) ($result['ResponseCode'] ?? '') === '0' && ($conversation !== '' || $originator !== '')) {
                        $this->recordMpesaVerificationRequest($payment, $receipt, $conversation, $originator, $request->user()?->id);

                        ActivityLogService::log('mpesa_verification_requested', $order, [
                            'payment_id'     => $payment->id,
                            'payment_number' => $payment->payment_number,
                            'receipt'        => $receipt,
                            'amount'         => $payment->amount,
                        ]);

                        return response()->json([
                            'message'        => "Asked Safaricom to confirm receipt {$receipt} — the payment will update here automatically when they answer (usually within a minute).",
                            'pending'        => true,
                            'payment_status' => $order->fresh()->payment_status,
                            'payment'        => $payment->fresh(),
                        ], 202);
                    }
                } catch (\Exception $e) {
                    Log::warning('Transaction status query failed', ['error' => $e->getMessage()]);
                }
            }

            return response()->json([
                'message' => 'Could not confirm payment with Daraja. Please check the transaction code and try again.',
                'daraja_response' => $darajaResponse,
            ], 422);

        } catch (\Exception $e) {
            Log::error('verifyMpesa error', [
                'order_id'   => $orderId,
                'payment_id' => $paymentId,
                'error'      => $e->getMessage(),
            ]);
            return ServerError::respond($e, 'Verification failed.');
        }
    }

    /**
     * Record the question asked of Safaricom on the payment, so its answer can
     * be matched (by Daraja's conversation ids) and the console can show
     * "Waiting for Safaricom…". A second press keeps the earlier ids, so an
     * answer to the first press still finds its payment.
     */
    private function recordMpesaVerificationRequest(Payment $payment, string $receipt, string $conversation, string $originator, ?int $requestedBy): void
    {
        DB::transaction(function () use ($payment, $receipt, $conversation, $originator, $requestedBy) {
            $locked   = Payment::whereKey($payment->id)->lockForUpdate()->first();
            $resp     = (array) ($locked->provider_response ?? []);
            $previous = (array) ($resp[self::MPESA_VERIFICATION] ?? []);

            $earlier = array_merge(
                (array) ($previous['earlier_conversations'] ?? []),
                [$previous['conversation_id'] ?? null, $previous['originator_conversation_id'] ?? null],
            );
            $earlier = array_values(array_unique(array_filter($earlier, fn ($id) => is_string($id) && $id !== '')));

            $resp[self::MPESA_VERIFICATION] = [
                'state'                      => 'pending',
                'receipt'                    => $receipt,
                'requested_by'               => $requestedBy,
                'requested_at'               => now()->toIso8601String(),
                'conversation_id'            => $conversation !== '' ? $conversation : null,
                'originator_conversation_id' => $originator !== '' ? $originator : null,
                'earlier_conversations'      => array_slice($earlier, -10),
            ];
            $locked->forceFill(['provider_response' => $resp])->save();
        });
    }

    /** Another payment already settled on this receipt, if any. */
    private function paymentHoldingReceipt(string $receipt, int $exceptPaymentId): ?Payment
    {
        return Payment::query()
            ->where('id', '!=', $exceptPaymentId)
            ->where('status', 'paid')
            ->whereRaw('UPPER(provider_reference) = ?', [$receipt])
            ->orderBy('id')
            ->first();
    }

    private function receiptTakenMessage(string $receipt, Payment $taken): string
    {
        $orderNumber = Order::withoutViewerScope()->whereKey($taken->order_id)->value('order_number') ?? ('#' . $taken->order_id);
        $number      = $taken->payment_number ?: ('#' . $taken->id);

        return "Receipt {$receipt} is already recorded on payment {$number} (order {$orderNumber}).";
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

                // Quote-originated invoice → issue receipt + commit stock (no-op otherwise).
                $this->settleReceipt($order, $payment);

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
                'message'        => $order->fresh()->isPayable()
                    ? 'Paystack payment confirmed successfully.'
                    : \App\Support\PaymentGate::lateMessage($order->fresh()),
                'payment_status' => $order->fresh()->payment_status,
                'payment'        => $payment->fresh(),
            ]);

        } catch (\Exception $e) {
            Log::error('verifyPaystack error', [
                'order_id'   => $orderId,
                'payment_id' => $paymentId,
                'error'      => $e->getMessage(),
            ]);
            return ServerError::respond($e, 'Verification failed.');
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
                : url('/api/webhooks/mpesa/callback');

            // The balance still due, not the whole total: a part-paid order
            // is not asked to pay twice.
            $due = round($order->outstandingBalance(), 2);
            if ($due <= 0) {
                return response()->json(['message' => 'Nothing is outstanding on this order.'], 422);
            }

            $result = $mpesa->stkPush(
                phone:       $phone,
                amount:      (int) ceil($due),
                reference:   $order->order_number,
                callbackUrl: $callbackUrl,
                description: "Order #{$order->order_number}"
            );

            // Record pending payment
            $payment = Payment::create([
                'recorded_by' => auth()->id(),   // who started it: staff, or the customer (4D)
                'order_id'           => $order->id,
                'payment_method'     => 'mpesa',
                'amount'             => $due,
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
                'amount'              => $due,
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
            return ServerError::respond($e, 'Failed to initiate M-Pesa payment.');
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
                'recorded_by' => auth()->id(),   // who started it: staff, or the customer (4D)
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
            return ServerError::respond($e, 'Failed to initiate payment.');
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
                'recorded_by' => auth()->id(),   // who started it: staff, or the customer (4D)
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
            return ServerError::respond($e, 'Failed to initiate payment.');
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

                    // Quote-originated invoice → issue receipt + commit stock (no-op otherwise).
                    $this->settleReceipt($order, $payment);

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
     * POST /webhooks/mpesa/transaction-result — Daraja's asynchronous answer to
     * a Transaction Status query (the staff "verify" button, strategy 2).
     *
     * The URL was sent to Safaricom but no route existed until 2026-10-26, so
     * every answer was lost — while the button had already marked the payment
     * paid on Daraja merely ACCEPTING the question. Now this answer is the only
     * thing that can settle a receipt-code verification, and only when it
     * proves the money (mpesaVerificationRefusal): matched to the request by
     * Daraja's conversation ids, ResultCode 0, TransactionStatus Completed, the
     * same receipt, enough money, our paybill/till, and a receipt not already
     * recorded elsewhere. Anything else is recorded on the payment, the
     * payment stays pending, and the staff member who asked (and finance)
     * hear why.
     *
     * This endpoint is public (Safaricom calls it), so a body can be forged.
     * That is why only a conversation-id match — ids only Safaricom and this
     * server know — can settle money; an answer tied to the request by its
     * receipt alone is recorded and reported, never applied.
     *
     * Always answers Safaricom 0: a failure to apply is logged loudly, and the
     * payment simply stays pending.
     */
    public function mpesaTransactionResult(Request $request)
    {
        $r      = (array) $request->input('Result', []);
        $params = $this->darajaResultParameters($r);
        // ReceiptNo is the receipt that was asked about; TransactionID is the
        // fallback (Daraja's examples carry the same value in both).
        $receipt = strtoupper(trim((string) ($params->get('ReceiptNo') ?? $r['TransactionID'] ?? '')));
        $info = [
            'result_code'     => $r['ResultCode'] ?? null,
            'result_desc'     => $r['ResultDesc'] ?? null,
            'receipt'         => $receipt,
            'amount'          => $params->get('Amount'),
            'status'          => $params->get('TransactionStatus'),
            'credit_party'    => $params->get('CreditPartyName'),
            'conversation'    => $r['OriginatorConversationID'] ?? null,
            'conversation_id' => $r['ConversationID'] ?? null,
            'received_at'     => now()->toIso8601String(),
        ];
        Log::info('M-Pesa transaction-status result', $info);

        try {
            $this->applyMpesaTransactionResult($this->darajaConversationIds($r), $receipt, $info);
        } catch (\Throwable $e) {
            Log::error('M-Pesa transaction-status result could not be applied — payment left pending', [
                'receipt' => $receipt,
                'error'   => $e->getMessage(),
            ]);
        }

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    /**
     * POST /webhooks/mpesa/transaction-timeout — Safaricom gave up on the
     * question. The pending verification is marked timed out and the staff
     * member who asked is told to try again or check the statement.
     */
    public function mpesaTransactionTimeout(Request $request)
    {
        $r   = (array) ($request->input('Result') ?: $request->all());
        $ids = $this->darajaConversationIds($r);
        Log::warning('M-Pesa transaction-status query timed out', ['conversation' => $ids]);

        try {
            [$paymentId, $how] = $this->matchMpesaVerification($ids, '');
            if ($paymentId && $how === 'conversation') {
                $timedOut = DB::transaction(function () use ($paymentId) {
                    $payment = Payment::whereKey($paymentId)->lockForUpdate()->first();
                    $resp    = (array) ($payment->provider_response ?? []);
                    $v       = (array) ($resp[self::MPESA_VERIFICATION] ?? []);
                    if (($v['state'] ?? null) !== 'pending') {
                        return null; // answered already, or a repeated timeout
                    }
                    $v['state']        = 'timed_out';
                    $v['timed_out_at'] = now()->toIso8601String();
                    $v['message']      = "Safaricom didn't answer — try Verify again or check the M-Pesa statement.";
                    $resp[self::MPESA_VERIFICATION] = $v;
                    $payment->forceFill(['provider_response' => $resp])->save();

                    return [$payment, $v];
                });

                if ($timedOut) {
                    [$payment, $v] = $timedOut;
                    $order = Order::withoutViewerScope()->find($payment->order_id);
                    if ($order) {
                        ActivityLogService::log('mpesa_verification_timed_out', $order, [
                            'payment_id'   => $payment->id,
                            'receipt'      => $v['receipt'] ?? null,
                            'requested_by' => $v['requested_by'] ?? null,
                        ]);
                        NotificationService::mpesaVerificationAnswer(
                            isset($v['requested_by']) ? (int) $v['requested_by'] : null,
                            $payment->id, $order->id, (string) $order->order_number,
                            'mpesa_verification_timed_out',
                            'M-Pesa receipt ' . ($v['receipt'] ?? '') . " — Safaricom didn't answer",
                            "Order #{$order->order_number}: Safaricom didn't answer — try Verify again or check the M-Pesa statement.",
                            false,
                        );
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('M-Pesa transaction-status timeout could not be recorded', ['error' => $e->getMessage()]);
        }

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    /**
     * Apply one Transaction Status answer. Locked per payment (and per receipt,
     * so two payments can never both settle on one receipt); a repeated
     * answer finds the verification closed and changes nothing.
     */
    private function applyMpesaTransactionResult(array $ids, string $receipt, array $info): void
    {
        [$paymentId, $how] = $this->matchMpesaVerification($ids, $receipt);

        if (!$paymentId) {
            // Not an answer to any verification we asked. Kept as before
            // (#500): noted on the payment holding that receipt, changing nothing.
            if ($receipt !== '') {
                $payment = Payment::where('provider_reference', $receipt)->latest('id')->first();
                if ($payment) {
                    $resp = (array) ($payment->provider_response ?? []);
                    $resp['mpesa_transaction_status'] = $info;
                    $payment->forceFill(['provider_response' => $resp])->save();
                }
            }
            Log::warning('M-Pesa transaction-status result matched no verification — ignored', [
                'receipt' => $receipt, 'conversation' => $ids,
            ]);
            return;
        }

        $outcome = DB::transaction(function () use ($paymentId, $how, $receipt, $info) {
            $this->lockMpesaReceipt($receipt);
            $payment = Payment::whereKey($paymentId)->lockForUpdate()->first();
            $resp    = (array) ($payment->provider_response ?? []);
            $v       = (array) ($resp[self::MPESA_VERIFICATION] ?? []);

            if (!in_array($v['state'] ?? null, self::MPESA_VERIFICATION_OPEN, true)) {
                return null; // already answered — a repeated callback does nothing new
            }
            // An answer to an earlier press of Verify counts only when it is
            // about the receipt being asked now.
            if ($how === 'earlier' && $receipt !== ($v['receipt'] ?? null)) {
                return null;
            }

            $resp['mpesa_transaction_status'] = $info;
            $v['answer']      = $info;
            $v['answered_at'] = now()->toIso8601String();
            $order = Order::withoutViewerScope()->find($payment->order_id);

            if ($how === 'receipt') {
                if (($v['state'] ?? null) === 'unmatched') {
                    return null; // reported once already
                }
                // Tied to the request by its receipt only — no conversation id
                // of ours. Anyone can post this endpoint, so it is never applied.
                // The verification stays open: Safaricom's real answer can
                // still settle it.
                $v['state']   = 'unmatched';
                $v['message'] = "Safaricom's answer about receipt {$v['receipt']} could not be tied to this request, so it was not applied. Press Verify again, or check the M-Pesa statement.";
                $resp[self::MPESA_VERIFICATION] = $v;
                $payment->forceFill(['provider_response' => $resp])->save();

                return ['kind' => 'unmatched', 'payment' => $payment, 'order' => $order, 'v' => $v];
            }

            if ($payment->status === 'paid') {
                // Settled meanwhile by another route (the STK confirmation).
                $v['state']   = 'superseded';
                $v['message'] = 'Already confirmed as paid.';
                $resp[self::MPESA_VERIFICATION] = $v;
                $payment->forceFill(['provider_response' => $resp])->save();

                return null;
            }

            $refusal = $order ? $this->mpesaVerificationRefusal($payment, $v, $info) : ['order_missing', 'The order for this payment no longer exists — nothing was recorded.'];
            if ($refusal) {
                [$reason, $message] = $refusal;
                $v['state']   = 'refused';
                $v['reason']  = $reason;
                $v['message'] = $message;
                $resp[self::MPESA_VERIFICATION] = $v;
                $payment->forceFill(['provider_response' => $resp])->save();

                return ['kind' => 'refused', 'payment' => $payment, 'order' => $order, 'v' => $v];
            }

            $v['state']   = 'paid';
            $v['message'] = "Safaricom confirmed receipt {$v['receipt']}.";
            $resp[self::MPESA_VERIFICATION] = $v;
            $this->applyMpesaSettlement($payment, $order, $v['receipt'], ['provider_response' => $resp]);

            return ['kind' => 'paid', 'payment' => $payment, 'order' => $order, 'v' => $v];
        });

        if (!$outcome || !$outcome['order']) {
            return;
        }

        /** @var Payment $payment */
        $payment     = $outcome['payment'];
        $order       = $outcome['order'];
        $v           = $outcome['v'];
        $requestedBy = isset($v['requested_by']) ? (int) $v['requested_by'] : null;

        if ($outcome['kind'] === 'paid') {
            $this->announceMpesaSettlement($payment, $order, $v['receipt'], 'transaction_status_result', [
                'requested_by' => $requestedBy,
                'amount_received' => $info['amount'],
            ]);
            // Finance heard it through paymentReceived; the person who asked
            // hears it too, wherever they are now.
            if ($requestedBy) {
                $order->refresh();
                $amount = number_format((float) $payment->amount, 2);
                NotificationService::mpesaVerificationAnswer(
                    $requestedBy, $payment->id, $order->id, (string) $order->order_number,
                    'mpesa_verification_confirmed',
                    "Safaricom confirmed M-Pesa receipt {$v['receipt']}",
                    $order->isPayable()
                        ? "KES {$amount} is recorded as paid on order #{$order->order_number}."
                        : \App\Support\PaymentGate::lateMessage($order),
                    false,
                );
            }
            return;
        }

        ActivityLogService::log('mpesa_verification_' . $outcome['kind'], $order, [
            'payment_id'   => $payment->id,
            'receipt'      => $v['receipt'] ?? null,
            'reason'       => $v['reason'] ?? $outcome['kind'],
            'requested_by' => $requestedBy,
            'answer'       => $info,
        ], "M-Pesa receipt " . ($v['receipt'] ?? '') . " not applied to payment {$payment->payment_number}: {$v['message']}");

        NotificationService::mpesaVerificationAnswer(
            $requestedBy, $payment->id, $order->id, (string) $order->order_number,
            'mpesa_verification_' . $outcome['kind'],
            'M-Pesa receipt ' . ($v['receipt'] ?? '') . " not confirmed — order #{$order->order_number}",
            $v['message'],
            true,
        );
    }

    /**
     * Why Safaricom's answer does NOT prove this payment, in plain words — or
     * null when it does. [reason code, message for staff].
     */
    private function mpesaVerificationRefusal(Payment $payment, array $v, array $info): ?array
    {
        $asked  = (string) ($v['receipt'] ?? '');
        $number = $payment->payment_number ?: ('#' . $payment->id);

        if ((string) ($info['result_code'] ?? '') !== '0') {
            $desc = trim((string) ($info['result_desc'] ?? '')) ?: 'no reason given';
            return ['failed', "Safaricom could not confirm receipt {$asked} ({$desc}). The payment was not recorded."];
        }

        $status = trim((string) ($info['status'] ?? ''));
        if (strcasecmp($status, 'Completed') !== 0) {
            $said = $status !== '' ? "\"{$status}\"" : 'not finished';
            return ['not_completed', "Safaricom says receipt {$asked} is {$said}, not completed. The payment was not recorded."];
        }

        if (($info['receipt'] ?? '') !== $asked) {
            return ['receipt_mismatch', "Safaricom answered about receipt {$info['receipt']}, not {$asked}. The payment was not recorded."];
        }

        if (in_array($payment->status, self::MPESA_CLOSED_PAYMENT, true)) {
            return ['payment_closed', "Payment {$number} is {$payment->status}, so Safaricom's confirmation of receipt {$asked} was not applied."];
        }

        if (strtoupper((string) $payment->currency_code) !== 'KES') {
            return ['currency', "Payment {$number} is in {$payment->currency_code}, but M-Pesa pays in KES, so receipt {$asked} was not applied. Record it by hand at the amount received."];
        }

        $amount = is_numeric($info['amount'] ?? null) ? round((float) $info['amount'], 2) : null;
        if ($amount === null) {
            return ['amount_unknown', "Safaricom did not say how much receipt {$asked} was for. The payment was not recorded — check the M-Pesa statement."];
        }
        $expected = round((float) $payment->amount, 2);
        // Exact means within KES 1; more than expected is still the money.
        if ($amount < round($expected - 1.0, 2)) {
            return ['amount_short', 'Receipt ' . $asked . ' is for KES ' . number_format($amount, 2)
                . ', but this payment expects KES ' . number_format($expected, 2)
                . '. It was not recorded — record the amount actually received instead.'];
        }

        $paidTo = $this->creditPartyShortcode($info['credit_party'] ?? null);
        $ours   = $this->ourMpesaShortcodes();
        if ($paidTo !== null && $ours !== [] && !in_array($paidTo, $ours, true)) {
            return ['wrong_till', "Receipt {$asked} was paid to M-Pesa number {$paidTo}, not ours (" . implode(', ', $ours) . '). It was not recorded.'];
        }

        if ($taken = $this->paymentHoldingReceipt($asked, $payment->id)) {
            return ['receipt_already_recorded', $this->receiptTakenMessage($asked, $taken)];
        }

        return null;
    }

    /**
     * Which payment's verification this answer belongs to, and how it was tied:
     * 'conversation' (Daraja's ids for the current request), 'earlier' (ids of
     * an earlier press of Verify on that payment), or 'receipt' (an open
     * request for that receipt within 24 hours — reported, never applied).
     *
     * @return array{0: ?int, 1: ?string}
     */
    private function matchMpesaVerification(array $ids, string $receipt): array
    {
        $key = 'provider_response->' . self::MPESA_VERIFICATION;

        if ($ids !== []) {
            $current = Payment::query()->where(function ($q) use ($ids, $key) {
                foreach ($ids as $id) {
                    $q->orWhere("{$key}->conversation_id", $id)
                      ->orWhere("{$key}->originator_conversation_id", $id);
                }
            })->orderByDesc('id')->value('id');
            if ($current) {
                return [(int) $current, 'conversation'];
            }

            $earlier = Payment::query()->where(function ($q) use ($ids, $key) {
                foreach ($ids as $id) {
                    $q->orWhereJsonContains("{$key}->earlier_conversations", $id);
                }
            })->orderByDesc('id')->value('id');
            if ($earlier) {
                return [(int) $earlier, 'earlier'];
            }
        }

        if ($receipt !== '') {
            $candidates = Payment::query()
                ->where("{$key}->receipt", $receipt)
                ->whereIn("{$key}->state", self::MPESA_VERIFICATION_OPEN)
                ->orderByDesc('id')
                ->get(['id', 'provider_response']);
            foreach ($candidates as $candidate) {
                $at = $candidate->provider_response[self::MPESA_VERIFICATION]['requested_at'] ?? null;
                try {
                    if ($at && \Carbon\Carbon::parse($at)->gte(now()->subDay())) {
                        return [(int) $candidate->id, 'receipt'];
                    }
                } catch (\Throwable) {
                    // an unreadable timestamp is simply not a match
                }
            }
        }

        return [null, null];
    }

    /** Daraja's ids for the question (either may be echoed), de-duplicated. */
    private function darajaConversationIds(array $r): array
    {
        return array_values(array_unique(array_filter([
            trim((string) ($r['OriginatorConversationID'] ?? '')),
            trim((string) ($r['ConversationID'] ?? '')),
        ], fn ($id) => $id !== '')));
    }

    /** ResultParameters as Key => Value (Daraja sends one object when there is one). */
    private function darajaResultParameters(array $r): \Illuminate\Support\Collection
    {
        $list = data_get($r, 'ResultParameters.ResultParameter', []);
        if (is_array($list) && array_key_exists('Key', $list)) {
            $list = [$list];
        }

        return collect(is_array($list) ? $list : [])
            ->filter(fn ($p) => is_array($p))
            ->mapWithKeys(fn ($p) => [(string) ($p['Key'] ?? '') => $p['Value'] ?? null]);
    }

    /** "600000 - Bethany House" → "600000"; null when Daraja names no number. */
    private function creditPartyShortcode($creditParty): ?string
    {
        return preg_match('/^\s*(\d{5,7})\b/', (string) $creditParty, $m) ? $m[1] : null;
    }

    /** The M-Pesa number(s) this business is paid on (the Daraja shortcode). */
    private function ourMpesaShortcodes(): array
    {
        try {
            $code = trim((new MpesaService())->shortcode());
        } catch (\Throwable $e) {
            Log::warning('M-Pesa shortcode unavailable for the paybill check', ['error' => $e->getMessage()]);
            return [];
        }

        return $code !== '' ? [$code] : [];
    }

    /** Serialise everything that may settle this receipt (Postgres advisory lock). */
    private function lockMpesaReceipt(string $receipt): void
    {
        if ($receipt !== '' && DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['mpesa-receipt:' . $receipt]);
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

                    // Quote-originated invoice → issue receipt + commit stock (no-op otherwise).
                    $this->settleReceipt($order, $payment);

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

                    // Quote-originated invoice → issue receipt + commit stock (no-op otherwise).
                    $this->settleReceipt($order, $payment);

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
            ->when($currency,
                fn ($q) => $q->where('currency_code', strtoupper($currency)),
                // No currency asked for: report in KES at the reporting rate.
                // The old default summed every currency at face value.
                fn ($q) => $q->whereRaw(\App\Support\ReportingCurrency::convertibleFilter('currency_code')));

        // Every money figure on ONE basis (Step 5): the asked-for currency
        // natively, else KES at each payment's own reporting rate. total_volume
        // was converted while paid/refunded/average summed currencies raw.
        $amt    = fn (string $col) => $currency ? $col : \App\Support\ReportingCurrency::kes($col, 'currency_code');
        $kpis = (clone $base)->selectRaw("
            COUNT(*)                                                              AS total_count,
            COALESCE(SUM({$amt('amount')}), 0)                                    AS total_volume,
            COUNT(CASE WHEN status = 'paid'    THEN 1 END)                       AS paid_count,
            COALESCE(SUM(CASE WHEN status = 'paid'   THEN {$amt('amount')} END), 0)        AS paid_volume,
            COUNT(CASE WHEN status = 'failed'  THEN 1 END)                       AS failed_count,
            COUNT(CASE WHEN status = 'pending' THEN 1 END)                       AS pending_count,
            COUNT(CASE WHEN refund_amount > 0  THEN 1 END)                       AS refunded_count,
            COALESCE(SUM(CASE WHEN refund_amount > 0 THEN {$amt('refund_amount')} END), 0) AS refunded_volume,
            COALESCE(AVG(CASE WHEN status = 'paid' THEN {$amt('amount')} END), 0)          AS avg_transaction
        ")->first();

        $successRate = $kpis->total_count > 0
            ? round(($kpis->paid_count / $kpis->total_count) * 100, 1)
            : 0;

        // Volume by payment method
        $byMethod = (clone $base)
            ->selectRaw("
                payment_method,
                COUNT(*) AS count,
                COALESCE(SUM(CASE WHEN status = 'paid' THEN {$amt('amount')} END), 0) AS volume
            ")
            ->groupBy('payment_method')
            ->orderByDesc('volume')
            ->get();

        // Daily trend
        $daily = (clone $base)
            ->selectRaw("
                DATE(created_at) AS date,
                COUNT(*) AS count,
                COALESCE(SUM(CASE WHEN status = 'paid'   THEN {$amt('amount')} END), 0) AS volume,
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

    /**
     * GET /admin/payment-transactions/export — the Payment Transactions list as
     * a CSV, with the same filters as the screen.
     *
     * It used to return JSON that the browser turned into a CSV itself — the
     * one file in the hub the server never saw leave. The file is built here
     * now, so it passes the download gate (approval, export id, owner's copy)
     * like every other export.
     */
    public function exportTransactions(Request $request)
    {
        $query = Payment::with(['order:id,order_number,customer_first_name,customer_last_name'])
            ->orderBy('created_at', 'desc')
            ->limit(5000);

        if ($request->filled('status'))         $query->where('status', $request->input('status'));
        if ($request->filled('payment_method')) $query->where('payment_method', $request->input('payment_method'));
        if ($request->filled('currency_code'))  $query->where('currency_code', $request->input('currency_code'));
        if ($request->filled('start_date'))     $query->whereDate('payments.created_at', '>=', $request->input('start_date'));
        if ($request->filled('end_date'))       $query->whereDate('payments.created_at', '<=', $request->input('end_date'));
        if ($request->filled('min_amount'))     $query->where('amount', '>=', $request->input('min_amount'));
        if ($request->filled('max_amount'))     $query->where('amount', '<=', $request->input('max_amount'));
        if ($request->filled('search')) {
            $term = $request->input('search');
            $query->where(fn ($q) => $q->where('payment_number', 'ILIKE', "%{$term}%")
                ->orWhereHas('order', fn ($o) => $o->where('order_number', 'ILIKE', "%{$term}%")));
        }

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['#', 'Reference', 'Order', 'Customer', 'Method', 'Amount', 'Currency', 'Status', 'Date']);
        foreach ($query->get()->values() as $i => $p) {
            fputcsv($out, [
                $i + 1,
                $p->payment_number,
                $p->order?->order_number ?? '',
                $p->order ? trim($p->order->customer_first_name . ' ' . $p->order->customer_last_name) : '',
                $p->payment_method,
                $p->amount,
                $p->currency_code,
                $p->status,
                optional($p->created_at)->format('Y-m-d H:i:s'),
            ]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        $from = $request->input('start_date', 'all');
        $to   = $request->input('end_date', now()->toDateString());

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"transactions-{$from}-{$to}.csv\"",
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
        ]);
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
    // Admin — Void & Reassign (Phase 3B: a request, executed on approval)
    // =========================================================================

    /**
     * POST /payment-transactions/{id}/void-request   (payments.request_void | payments.void)
     * POST /payment-transactions/{id}/void           (payments.void — kept for older clients)
     *
     * Asks for the payment to be voided. Nothing happens to the payment now:
     * the approval engine routes the request (≤ KES 50,000 finance; above,
     * finance then the super admin) and the void is carried out by
     * App\Services\PaymentCorrections when the last band signs. The person
     * who asks never signs it (maker ≠ checker).
     */
    public function voidPayment(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $payment = \App\Models\Payment::findOrFail($id);

        if ($payment->status === 'voided') {
            return response()->json(['message' => 'Payment is already voided.'], 422);
        }

        $engine   = app(\App\Services\Approvals\ApprovalEngine::class);
        $approval = $engine->submit('payment_void', $payment, $request->user(), ['reason' => $validated['reason']]);

        return response()->json([
            'message'  => 'Void requested. It takes effect when finance approves it.',
            'payment'  => $payment->fresh(),
            'approval' => $engine->present($approval->load(['signatures', 'maker']), $request->user()),
        ], 202);
    }

    /**
     * POST /payment-transactions/{id}/reassign-request   (payments.request_reassign | payments.reassign)
     * POST /payment-transactions/{id}/reassign           (payments.reassign — kept for older clients)
     *
     * Asks for the payment to be moved to another order; carried out on
     * approval, exactly as a void.
     */
    public function reassignPayment(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'order_id' => 'required|integer|exists:orders,id',
            'reason'   => 'required|string|max:1000',
        ]);

        $payment = \App\Models\Payment::findOrFail($id);

        if ($payment->status === 'voided') {
            return response()->json(['message' => 'Voided payments cannot be reassigned.'], 422);
        }
        if ((int) $payment->order_id === (int) $validated['order_id']) {
            return response()->json(['message' => 'Payment is already assigned to that order.'], 422);
        }
        // Money moves only between orders in its own currency (PaymentCorrections).
        $targetCurrency = \App\Models\Order::withoutViewerScope()->whereKey($validated['order_id'])->value('currency_code');
        if (strtoupper((string) $targetCurrency) !== strtoupper((string) $payment->currency_code)) {
            return response()->json(['message' => "This payment is in {$payment->currency_code}; that order is in {$targetCurrency}. A payment moves only between orders in the same currency."], 422);
        }

        $engine   = app(\App\Services\Approvals\ApprovalEngine::class);
        $approval = $engine->submit('payment_reassign', $payment, $request->user(), [
            'order_id' => (int) $validated['order_id'],
            'reason'   => $validated['reason'],
        ]);

        return response()->json([
            'message'  => 'Move requested. It takes effect when finance approves it.',
            'payment'  => $payment->fresh(),
            'approval' => $engine->present($approval->load(['signatures', 'maker']), $request->user()),
        ], 202);
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
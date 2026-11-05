<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use App\Models\MpesaPurchaseIntent;
use App\Models\Subscription;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PaymentService
{
    public function initiatePurchaseIntent(MpesaPurchaseIntent $intent, User $payer, string $phone): array
    {
        $intent->refresh();
        if ($intent->user_id !== $payer->id) {
            abort(403, 'Only the purchase owner can initiate payment.');
        }
        if (!in_array($intent->status, ['initiated', 'failed', 'cancelled'], true)) {
            abort(409, 'This purchase is not awaiting payment.');
        }
        if ($intent->currency !== 'KES' || $intent->amount_minor < 100 || $intent->amount_minor % 100 !== 0) {
            throw ValidationException::withMessages([
                'amount' => 'M-Pesa STK Push requires an exact whole-KES amount.',
            ]);
        }
        try {
            $this->assertConfiguration();
            $phone = $this->normalizePhone($phone);
            $accessToken = $this->accessToken();
        } catch (RuntimeException|\Illuminate\Validation\ValidationException $exception) {
            MpesaPurchaseIntent::query()->whereKey($intent->id)->where('status', 'initiated')->update(['status' => 'failed']);
            throw $exception;
        }

        DB::transaction(function () use ($intent, $payer): void {
            $locked = MpesaPurchaseIntent::query()->lockForUpdate()->findOrFail($intent->id);
            abort_unless($locked->user_id === $payer->id, 403, 'Only the purchase owner can initiate payment.');
            abort_unless(in_array($locked->status, ['initiated', 'failed', 'cancelled'], true), 409, 'This purchase is not awaiting payment.');
            $locked->forceFill(['status' => 'pending'])->save();
        });

        $timestamp = now()->format('YmdHis');
        $shortcode = (string) config('services.mpesa.shortcode');
        $password = base64_encode($shortcode . (string) config('services.mpesa.passkey') . $timestamp);
        $reference = 'FLP-' . strtoupper(substr(str_replace('-', '', $intent->id), 0, 10));
        $description = $intent->subscription_id ? 'FarmLink premium subscription' : 'FarmLink advertising campaign';

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->timeout(15)
                ->post($this->baseUrl() . '/mpesa/stkpush/v1/processrequest', [
                    'BusinessShortCode' => $shortcode,
                    'Password' => $password,
                    'Timestamp' => $timestamp,
                    'TransactionType' => 'CustomerPayBillOnline',
                    'Amount' => intdiv((int) $intent->amount_minor, 100),
                    'PartyA' => $phone,
                    'PartyB' => $shortcode,
                    'PhoneNumber' => $phone,
                    'CallBackURL' => (string) config('services.mpesa.callback_url'),
                    'AccountReference' => $reference,
                    'TransactionDesc' => $description,
                ]);
        } catch (ConnectionException $exception) {
            Log::error('M-Pesa purchase STK endpoint is unreachable.', ['purchase_intent_id' => $intent->id]);
            throw new RuntimeException('The payment provider is temporarily unavailable.', 0, $exception);
        }

        if (!$response->successful() || (string) $response->json('ResponseCode') !== '0') {
            Log::warning('M-Pesa purchase STK request was rejected.', [
                'purchase_intent_id' => $intent->id,
                'http_status' => $response->status(),
            ]);
            MpesaPurchaseIntent::query()->whereKey($intent->id)->whereNull('checkout_request_id')
                ->where('status', 'pending')->update(['status' => 'failed']);
            throw new RuntimeException('The payment provider could not start the payment request.');
        }

        $checkoutId = (string) $response->json('CheckoutRequestID');
        $merchantId = (string) $response->json('MerchantRequestID');
        if ($checkoutId === '' || $merchantId === '') {
            Log::error('M-Pesa purchase response omitted required request identifiers.', ['purchase_intent_id' => $intent->id]);
            throw new RuntimeException('The payment provider returned an incomplete response.');
        }

        $intent->forceFill([
            'checkout_request_id' => $checkoutId,
            'merchant_request_id' => $merchantId,
        ])->save();

        return [
            'purchase_id' => $intent->id,
            'status' => 'pending',
            'provider' => 'mpesa',
            'checkout_reference' => $checkoutId,
            'amount_minor' => $intent->amount_minor,
            'currency' => $intent->currency,
            'customer_message' => $response->json('CustomerMessage'),
        ];
    }

    public function initiateStkPush(Transaction $transaction, User $buyer, string $phone): array
    {
        $transaction->refresh();
        if ($transaction->buyer_id !== $buyer->id) {
            abort(403, 'Only the purchasing buyer can initiate payment.');
        }
        if ($transaction->status !== 'awaiting_payment'
            || !in_array($transaction->payment_status, ['initiated', 'failed', 'cancelled'], true)) {
            abort(409, 'This transaction is not awaiting payment.');
        }
        if ($transaction->currency !== 'KES' || $transaction->total_minor < 100 || $transaction->total_minor % 100 !== 0) {
            throw ValidationException::withMessages([
                'amount' => 'M-Pesa STK Push requires an exact whole-KES amount.',
            ]);
        }
        $this->assertConfiguration();

        $phone = $this->normalizePhone($phone);
        $accessToken = $this->accessToken();
        DB::transaction(function () use ($transaction, $buyer): void {
            $locked = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);
            if ($locked->buyer_id !== $buyer->id) {
                abort(403, 'Only the purchasing buyer can initiate payment.');
            }
            if ($locked->status !== 'awaiting_payment'
                || !in_array($locked->payment_status, ['initiated', 'failed', 'cancelled'], true)) {
                abort(409, 'This transaction is not awaiting payment.');
            }

            $locked->forceFill(['payment_status' => 'pending'])->save();
        });

        $timestamp = now()->format('YmdHis');
        $shortcode = (string) config('services.mpesa.shortcode');
        $passkey = (string) config('services.mpesa.passkey');
        $password = base64_encode($shortcode . $passkey . $timestamp);

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->timeout(15)
                ->post($this->baseUrl() . '/mpesa/stkpush/v1/processrequest', [
                    'BusinessShortCode' => $shortcode,
                    'Password' => $password,
                    'Timestamp' => $timestamp,
                    'TransactionType' => 'CustomerPayBillOnline',
                    'Amount' => intdiv((int) $transaction->total_minor, 100),
                    'PartyA' => $phone,
                    'PartyB' => $shortcode,
                    'PhoneNumber' => $phone,
                    'CallBackURL' => (string) config('services.mpesa.callback_url'),
                    'AccountReference' => 'FL-' . substr(str_replace('-', '', $transaction->id), 0, 12),
                    'TransactionDesc' => 'FarmLink order payment',
                ]);
        } catch (ConnectionException $exception) {
            Log::error('M-Pesa STK endpoint is unreachable.', ['transaction_id' => $transaction->id]);
            throw new RuntimeException('The payment provider is temporarily unavailable.', 0, $exception);
        }

        if (!$response->successful() || (string) $response->json('ResponseCode') !== '0') {
            Log::warning('M-Pesa STK request was rejected.', [
                'transaction_id' => $transaction->id,
                'http_status' => $response->status(),
                'provider_code' => $response->json('errorCode'),
            ]);
            if ($response->successful()) {
                Transaction::query()
                    ->whereKey($transaction->id)
                    ->whereNull('checkout_request_id')
                    ->where('payment_status', 'pending')
                    ->update(['payment_status' => 'initiated', 'updated_at' => now()]);
            }
            throw new RuntimeException('The payment provider could not start the payment request.');
        }

        $checkoutId = (string) $response->json('CheckoutRequestID');
        $merchantId = (string) $response->json('MerchantRequestID');
        if ($checkoutId === '' || $merchantId === '') {
            Log::error('M-Pesa STK response omitted required request identifiers.', [
                'transaction_id' => $transaction->id,
            ]);
            throw new RuntimeException('The payment provider returned an incomplete response.');
        }

        $transaction->forceFill([
            'checkout_request_id' => $checkoutId,
            'merchant_request_id' => $merchantId,
        ])->save();

        return [
            'transaction_id' => $transaction->id,
            'status' => 'pending',
            'provider' => 'mpesa',
            'checkout_reference' => $checkoutId,
            'amount_minor' => $transaction->total_minor,
            'currency' => $transaction->currency,
            'escrow_mode' => 'simulated',
            'customer_message' => $response->json('CustomerMessage'),
        ];
    }

    public function handleCallback(array $payload): array
    {
        $callback = data_get($payload, 'Body.stkCallback');
        $checkoutId = (string) data_get($callback, 'CheckoutRequestID', '');
        $resultCode = data_get($callback, 'ResultCode');

        if ($checkoutId === '' || !is_numeric($resultCode)) {
            Log::warning('M-Pesa callback failed structural validation.');
            return ['ResultCode' => 1, 'ResultDesc' => 'Invalid callback'];
        }

        $transaction = Transaction::query()->where('checkout_request_id', $checkoutId)->first();
        $purchaseIntent = $transaction ? null : MpesaPurchaseIntent::query()->where('checkout_request_id', $checkoutId)->first();
        if (!$transaction && !$purchaseIntent) {
            Log::warning('M-Pesa callback references an unknown checkout.', ['checkout_id' => $checkoutId]);
            return ['ResultCode' => 1, 'ResultDesc' => 'Unknown checkout'];
        }
        $merchantId = $transaction?->merchant_request_id ?? $purchaseIntent?->merchant_request_id;
        if ((string) data_get($callback, 'MerchantRequestID', '') !== (string) $merchantId) {
            Log::warning('M-Pesa callback merchant reference did not match.', ['checkout_id' => $checkoutId]);
            return ['ResultCode' => 1, 'ResultDesc' => 'Invalid callback'];
        }

        if (DB::table('mpesa_callback_events')->where('checkout_request_id', $checkoutId)->exists()) {
            return ['ResultCode' => 0, 'ResultDesc' => 'Callback already processed'];
        }

        $providerResult = (int) $resultCode;
        $queryResult = $this->queryStkStatus($checkoutId);
        if ((string) data_get($queryResult, 'CheckoutRequestID', '') !== $checkoutId
            || (int) data_get($queryResult, 'ResultCode', PHP_INT_MIN) !== $providerResult
            || (int) data_get($queryResult, 'ResponseCode', -1) !== 0) {
            Log::warning('M-Pesa callback did not match the provider payment status.', [
                'transaction_id' => $transaction?->id,
                'purchase_intent_id' => $purchaseIntent?->id,
                'checkout_id' => $checkoutId,
            ]);
            return ['ResultCode' => 1, 'ResultDesc' => 'Payment verification failed'];
        }

        $receipt = null;
        if ($providerResult === 0) {
            $callbackAmount = collect(data_get($callback, 'CallbackMetadata.Item', []))
                ->firstWhere('Name', 'Amount')['Value'] ?? null;
            $receipt = collect(data_get($callback, 'CallbackMetadata.Item', []))
                ->firstWhere('Name', 'MpesaReceiptNumber')['Value'] ?? null;

            $amountAsInteger = filter_var($callbackAmount, FILTER_VALIDATE_INT);
            if ($amountAsInteger === false || (string) $receipt === '') {
                Log::warning('Successful M-Pesa callback omitted amount or receipt.', [
                    'transaction_id' => $transaction?->id,
                    'purchase_intent_id' => $purchaseIntent?->id,
                ]);
                return ['ResultCode' => 1, 'ResultDesc' => 'Missing payment details'];
            }

            $expectedAmountMinor = $transaction?->total_minor ?? $purchaseIntent?->amount_minor;
            if ($amountAsInteger !== intdiv((int) $expectedAmountMinor, 100)) {
                Log::critical('M-Pesa callback did not match verified payment details.', [
                    'transaction_id' => $transaction?->id,
                    'purchase_intent_id' => $purchaseIntent?->id,
                    'checkout_id' => $checkoutId,
                ]);
                return ['ResultCode' => 1, 'ResultDesc' => 'Payment verification failed'];
            }
        }

        DB::transaction(function () use ($transaction, $purchaseIntent, $checkoutId, $providerResult, $receipt): void {
            DB::table('mpesa_callback_events')->insertOrIgnore([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'checkout_request_id' => $checkoutId,
                'result_code' => (string) $providerResult,
                'receipt' => $receipt,
                'received_at' => now(),
            ]);

            if ($transaction) {
                $locked = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);
                if ($locked->payment_status !== 'pending') {
                    return;
                }

                if ($providerResult === 0) {
                    $locked->forceFill([
                        'payment_status' => 'succeeded',
                        'status' => 'escrow_held',
                        'escrow_status' => 'held',
                        'mpesa_receipt' => (string) $receipt,
                        'paid_at' => now(),
                    ])->save();
                } else {
                    $locked->forceFill(['payment_status' => 'failed'])->save();
                }
                return;
            }

            $purchase = MpesaPurchaseIntent::query()->lockForUpdate()->findOrFail($purchaseIntent->id);
            if ($purchase->status !== 'pending') {
                return;
            }
            if ($providerResult !== 0) {
                $purchase->forceFill(['status' => 'failed'])->save();
                return;
            }

            $purchase->forceFill(['status' => 'succeeded', 'receipt' => (string) $receipt, 'paid_at' => now()])->save();
            if ($purchase->subscription_id !== null) {
                $subscription = Subscription::query()->lockForUpdate()->findOrFail($purchase->subscription_id);
                $isRenewal = $subscription->ends_at?->isFuture() === true;
                $start = $isRenewal ? $subscription->ends_at : now();
                $subscription->forceFill([
                    'plan' => 'premium_monthly',
                    'status' => 'active',
                    'starts_at' => $isRenewal && $subscription->starts_at ? $subscription->starts_at : now(),
                    'ends_at' => $start->copy()->addDays(30),
                ])->save();
            } elseif ($purchase->ad_campaign_id !== null) {
                \App\Models\AdCampaign::query()->whereKey($purchase->ad_campaign_id)
                    ->where('status', 'draft')
                    ->update(['status' => 'pending_review', 'updated_at' => now()]);
            }
        });

        return ['ResultCode' => 0, 'ResultDesc' => 'Accepted'];
    }

    public function confirmDeliveryAndRelease(Transaction $transaction, User $buyer): Transaction
    {
        return DB::transaction(function () use ($transaction, $buyer): Transaction {
            $locked = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);
            if ($locked->buyer_id !== $buyer->id) {
                abort(403, 'Only the purchasing buyer can confirm delivery.');
            }
            if ($locked->status !== 'escrow_held' || $locked->escrow_status !== 'held') {
                abort(409, 'This transaction is not eligible for delivery confirmation.');
            }

            $locked->forceFill([
                'status' => 'completed',
                'escrow_status' => 'released',
                'delivered_at' => now(),
            ])->save();

            return $locked;
        });
    }

    private function queryStkStatus(string $checkoutId): array
    {
        $timestamp = now()->format('YmdHis');
        $shortcode = (string) config('services.mpesa.shortcode');
        $password = base64_encode($shortcode . (string) config('services.mpesa.passkey') . $timestamp);

        try {
            $response = Http::withToken($this->accessToken())
                ->acceptJson()
                ->timeout(15)
                ->post($this->baseUrl() . '/mpesa/stkpushquery/v1/query', [
                    'BusinessShortCode' => $shortcode,
                    'Password' => $password,
                    'Timestamp' => $timestamp,
                    'CheckoutRequestID' => $checkoutId,
                ]);
        } catch (ConnectionException $exception) {
            Log::error('M-Pesa payment verification endpoint is unreachable.');
            throw new RuntimeException('The payment provider could not verify the payment.', 0, $exception);
        }

        if (!$response->successful()) {
            Log::error('M-Pesa payment verification request failed.', ['http_status' => $response->status()]);
            throw new RuntimeException('The payment provider could not verify the payment.');
        }

        return $response->json() ?? [];
    }

    private function accessToken(): string
    {
        $key = (string) config('services.mpesa.consumer_key');
        $secret = (string) config('services.mpesa.consumer_secret');
        if ($key === '' || $secret === '') {
            throw new RuntimeException('M-Pesa API credentials are not configured.');
        }

        try {
            $response = Http::withBasicAuth($key, $secret)
                ->acceptJson()
                ->timeout(10)
                ->get($this->baseUrl() . '/oauth/v1/generate', ['grant_type' => 'client_credentials']);
        } catch (ConnectionException $exception) {
            Log::error('M-Pesa token endpoint is unreachable.');
            throw new RuntimeException('The payment provider is temporarily unavailable.', 0, $exception);
        }

        $token = $response->json('access_token');
        if (!$response->successful() || !is_string($token) || $token === '') {
            Log::error('M-Pesa token request failed.', ['http_status' => $response->status()]);
            throw new RuntimeException('The payment provider is temporarily unavailable.');
        }

        return $token;
    }

    private function assertConfiguration(): void
    {
        foreach (['shortcode', 'passkey', 'callback_url', 'consumer_key', 'consumer_secret'] as $setting) {
            if ((string) config('services.mpesa.' . $setting) === '') {
                throw new RuntimeException('M-Pesa payment configuration is incomplete.');
            }
        }

        if (!str_starts_with((string) config('services.mpesa.callback_url'), 'https://')) {
            throw new RuntimeException('The M-Pesa callback URL must use HTTPS.');
        }
    }

    private function baseUrl(): string
    {
        return config('services.mpesa.environment') === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }

    private function normalizePhone(string $phone): string
    {
        $normalized = preg_replace('/[\s()-]/', '', $phone);
        if (is_string($normalized) && preg_match('/^(?:\+?254|0)([17]\d{8})$/', $normalized, $matches)) {
            return '254' . $matches[1];
        }

        throw ValidationException::withMessages(['phone' => 'Enter a valid Kenyan M-Pesa phone number.']);
    }
}

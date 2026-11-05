<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Services\PaymentService;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.mpesa.environment' => 'sandbox',
            'services.mpesa.consumer_key' => 'test-key',
            'services.mpesa.consumer_secret' => 'test-secret',
            'services.mpesa.shortcode' => '174379',
            'services.mpesa.passkey' => 'test-passkey',
            'services.mpesa.callback_url' => 'https://example.test/api/v1/webhooks/mpesa',
        ]);
    }

    public function test_successful_callback_marks_payment_paid_but_does_not_claim_real_escrow(): void
    {
        $transaction = $this->transaction();
        $transaction->forceFill([
            'payment_status' => 'pending',
            'checkout_request_id' => 'ws_CO_success',
            'merchant_request_id' => 'merchant-success',
        ])->save();

        Http::fake([
            'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'test-token']),
            'sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'ResultCode' => '0',
                'CheckoutRequestID' => 'ws_CO_success',
            ]),
        ]);

        $result = app(PaymentService::class)->handleCallback($this->callback(
            'ws_CO_success',
            'merchant-success',
            0,
            [['Name' => 'Amount', 'Value' => 100], ['Name' => 'MpesaReceiptNumber', 'Value' => 'QAB123456']],
        ));

        $this->assertSame(0, $result['ResultCode']);
        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'payment_status' => 'succeeded',
            'escrow_status' => 'held',
            'mpesa_receipt' => 'QAB123456',
        ]);
        $this->assertDatabaseHas('mpesa_callback_events', ['checkout_request_id' => 'ws_CO_success']);
    }

    public function test_failed_callback_does_not_mark_payment_paid_or_hold_escrow(): void
    {
        $transaction = $this->transaction();
        $transaction->forceFill([
            'payment_status' => 'pending',
            'checkout_request_id' => 'ws_CO_failed',
            'merchant_request_id' => 'merchant-failed',
        ])->save();

        Http::fake([
            'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'test-token']),
            'sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'ResultCode' => '1032',
                'CheckoutRequestID' => 'ws_CO_failed',
            ]),
        ]);

        $result = app(PaymentService::class)->handleCallback(
            $this->callback('ws_CO_failed', 'merchant-failed', 1032, []),
        );

        $this->assertSame(0, $result['ResultCode']);
        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'payment_status' => 'failed',
            'escrow_status' => 'not_funded',
        ]);
    }

    public function test_success_callback_with_wrong_amount_is_not_accepted(): void
    {
        $transaction = $this->transaction();
        $transaction->forceFill([
            'payment_status' => 'pending',
            'checkout_request_id' => 'ws_CO_mismatch',
            'merchant_request_id' => 'merchant-mismatch',
        ])->save();

        Http::fake([
            'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'test-token']),
            'sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'ResultCode' => '0',
                'CheckoutRequestID' => 'ws_CO_mismatch',
            ]),
        ]);

        $result = app(PaymentService::class)->handleCallback($this->callback(
            'ws_CO_mismatch',
            'merchant-mismatch',
            0,
            [['Name' => 'Amount', 'Value' => 999], ['Name' => 'MpesaReceiptNumber', 'Value' => 'QAB999999']],
        ));

        $this->assertSame(1, $result['ResultCode']);
        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'payment_status' => 'pending',
            'escrow_status' => 'not_funded',
        ]);
    }

    private function transaction(): Transaction
    {
        $seller = $this->user('farmer');
        $buyer = $this->user('buyer');
        $product = \App\Models\Product::create([
            'seller_id' => $seller->id,
            'category' => 'produce',
            'name' => 'Maize',
            'crop' => 'maize',
            'quantity_available' => 10,
            'unit' => 'kg',
            'price_minor' => 5000,
            'currency' => 'KES',
        ]);
        $bid = \App\Models\Bid::create([
            'product_id' => $product->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'status' => 'accepted',
        ]);

        return Transaction::create([
            'bid_id' => $bid->id,
            'product_id' => $product->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'quantity' => 2,
            'unit' => 'kg',
            'unit_price_minor' => 5000,
            'total_minor' => 10000,
            'currency' => 'KES',
            'status' => 'awaiting_payment',
            'payment_status' => 'pending',
            'escrow_status' => 'not_funded',
        ]);
    }

    private function user(string $role): User
    {
        return User::create([
            'name' => ucfirst($role),
            'phone' => '2547' . random_int(10000000, 99999999),
            'password' => bcrypt('test-password-123'),
            'role' => $role,
            'kyc_status' => 'verified',
        ]);
    }

    private function callback(string $checkout, string $merchant, int $resultCode, array $metadata): array
    {
        return [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => $merchant,
                    'CheckoutRequestID' => $checkout,
                    'ResultCode' => $resultCode,
                    'CallbackMetadata' => ['Item' => $metadata],
                ],
            ],
        ];
    }
}

<?php

namespace Tests\Feature;

use App\Models\MpesaPurchaseIntent;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PremiumSubscriptionPaymentTest extends TestCase
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

    public function test_verified_mpesa_callback_activates_a_thirty_day_premium_subscription(): void
    {
        $user = User::create([
            'name' => 'Premium Farmer',
            'phone' => '254700000001',
            'password' => bcrypt('test-password-123'),
            'role' => 'farmer',
            'kyc_status' => 'verified',
        ]);
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan' => 'premium_monthly',
            'status' => 'inactive',
        ]);
        $intent = MpesaPurchaseIntent::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'amount_minor' => 99900,
            'currency' => 'KES',
            'status' => 'pending',
            'checkout_request_id' => 'ws_CO_premium',
            'merchant_request_id' => 'merchant-premium',
        ]);

        Http::fake([
            'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'test-token']),
            'sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'ResultCode' => '0',
                'CheckoutRequestID' => 'ws_CO_premium',
            ]),
        ]);

        $result = app(PaymentService::class)->handleCallback([
            'Body' => ['stkCallback' => [
                'MerchantRequestID' => 'merchant-premium',
                'CheckoutRequestID' => 'ws_CO_premium',
                'ResultCode' => 0,
                'CallbackMetadata' => ['Item' => [
                    ['Name' => 'Amount', 'Value' => 999],
                    ['Name' => 'MpesaReceiptNumber', 'Value' => 'QAP123456'],
                ]],
            ]],
        ]);

        $this->assertSame(0, $result['ResultCode']);
        $this->assertDatabaseHas('mpesa_purchase_intents', [
            'id' => $intent->id,
            'status' => 'succeeded',
            'receipt' => 'QAP123456',
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'status' => 'active',
            'plan' => 'premium_monthly',
        ]);
        $this->assertTrue($subscription->fresh()->ends_at->isFuture());
    }

    public function test_active_premium_sellers_receive_marketplace_visibility_priority(): void
    {
        $standardSeller = $this->user('farmer');
        $premiumSeller = $this->user('farmer');
        $standardProduct = $this->product($standardSeller, 'Standard listing');
        $premiumProduct = $this->product($premiumSeller, 'Premium listing');
        Subscription::create([
            'user_id' => $premiumSeller->id,
            'plan' => 'premium_monthly',
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(29),
        ]);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.0.id', $premiumProduct->id);

        $this->assertNotSame($standardProduct->id, $premiumProduct->id);
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

    private function product(User $seller, string $name): Product
    {
        return Product::create([
            'seller_id' => $seller->id,
            'category' => 'produce',
            'name' => $name,
            'crop' => 'maize',
            'quantity_available' => 10,
            'unit' => 'kg',
            'price_minor' => 5000,
            'currency' => 'KES',
            'status' => 'active',
        ]);
    }
}

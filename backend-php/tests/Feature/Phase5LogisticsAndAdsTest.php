<?php

namespace Tests\Feature;

use App\Models\AdCampaign;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase5LogisticsAndAdsTest extends TestCase
{
    use RefreshDatabase;

    public function test_buyer_can_request_delivery_and_provider_advances_valid_statuses(): void
    {
        $seller = $this->user('farmer');
        $buyer = $this->user('buyer');
        $provider = $this->user('transport_provider');
        $seller->profile()->create(['county' => 'Nakuru']);
        $buyer->profile()->create(['county' => 'Nairobi']);
        $product = Product::create([
            'seller_id' => $seller->id,
            'category' => 'produce',
            'name' => 'Maize',
            'crop' => 'maize',
            'quantity_available' => 10,
            'unit' => 'kg',
            'price_minor' => 5000,
            'currency' => 'KES',
            'county' => 'Nakuru',
        ]);
        $bid = \App\Models\Bid::create([
            'product_id' => $product->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'status' => 'accepted',
        ]);
        $transaction = Transaction::create([
            'bid_id' => $bid->id,
            'product_id' => $product->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'quantity' => 2,
            'unit' => 'kg',
            'unit_price_minor' => 5000,
            'total_minor' => 10000,
            'currency' => 'KES',
            'status' => 'escrow_held',
            'payment_status' => 'succeeded',
            'escrow_status' => 'held',
        ]);
        $provider->transportService()->create([
            'county' => 'Nakuru',
            'vehicle_type' => 'pickup',
            'available' => true,
        ]);

        $this->withToken($this->token($buyer))
            ->postJson("/api/v1/transactions/{$transaction->id}/delivery", [], ['Idempotency-Key' => $this->uuid()])
            ->assertCreated()
            ->assertJsonPath('matching_method', 'county_level_not_gps');
        $delivery = \App\Models\Delivery::query()->firstOrFail();

        $this->withToken($this->token($provider))
            ->getJson('/api/v1/transport/deliveries')
            ->assertOk()
            ->assertJsonPath('data.0.id', $delivery->id);
        $this->withToken($this->token($provider))
            ->postJson("/api/v1/deliveries/{$delivery->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'assigned');
        $this->withToken($this->token($provider))
            ->patchJson("/api/v1/deliveries/{$delivery->id}/status", ['status' => 'picked_up'])
            ->assertOk()
            ->assertJsonPath('data.status', 'picked_up');
        $this->withToken($this->token($buyer))
            ->getJson("/api/v1/deliveries/{$delivery->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'picked_up');
        $this->withToken($this->token($provider))
            ->patchJson("/api/v1/deliveries/{$delivery->id}/status", ['status' => 'delivered'])
            ->assertStatus(409);
    }

    public function test_ad_metrics_are_deduplicated_per_event_and_advertiser_audience(): void
    {
        $farmer = $this->user('farmer');
        $campaign = AdCampaign::create([
            'advertiser_id' => $this->user('supplier')->id,
            'name' => 'Seed offer',
            'target_role' => 'farmer',
            'creative_path' => 'ad-creatives/creative.png',
            'destination_url' => 'https://example.test/offer',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDays(30),
        ]);
        $headers = ['Authorization' => 'Bearer ' . $this->token($farmer)];

        $this->postJson("/api/v1/ads/{$campaign->id}/events/impression", [], $headers)->assertOk();
        $this->postJson("/api/v1/ads/{$campaign->id}/events/impression", [], $headers)->assertOk();
        $this->postJson("/api/v1/ads/{$campaign->id}/events/click", [], $headers)->assertOk();

        $this->assertDatabaseHas('ad_campaigns', [
            'id' => $campaign->id,
            'impressions_count' => 1,
            'clicks_count' => 1,
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

    private function token(User $user): string
    {
        return JWT::encode([
            'iss' => (string) config('app.url'),
            'sub' => (string) $user->id,
            'iat' => time(),
            'nbf' => time(),
            'exp' => time() + 3600,
            'jti' => $this->uuid(),
        ], (string) config('jwt.secret'), 'HS256');
    }

    private function uuid(): string
    {
        return (string) Str::uuid();
    }
}

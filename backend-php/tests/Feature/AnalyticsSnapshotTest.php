<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AnalyticsSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_market_snapshots_are_created_only_for_cohorts_with_five_recent_completed_orders(): void
    {
        $this->createOrders(4, 'maize');
        $this->createOrders(5, 'beans');

        $count = app(AnalyticsSnapshotService::class)->refresh();

        $this->assertSame(1, $count);
        $response = $this->getJson('/api/v1/analytics/market?crop=beans');
        $response->assertOk()
            ->assertJsonPath('meta.privacy_threshold', 5)
            ->assertJsonPath('meta.classification', 'historical_market_baseline_not_personalized_advice')
            ->assertJsonPath('data.0.dimensions.crop', 'beans')
            ->assertJsonPath('data.0.metrics.sample_size', 5)
            ->assertJsonPath('data.0.metrics.completed_orders_30d', 5);

        $this->getJson('/api/v1/analytics/market?crop=maize')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    private function createOrders(int $count, string $crop): void
    {
        $seller = $this->user('farmer');
        $buyer = $this->user('buyer');
        $product = Product::create([
            'seller_id' => $seller->id,
            'category' => 'produce',
            'name' => ucfirst($crop),
            'crop' => $crop,
            'quantity_available' => 10,
            'unit' => 'kg',
            'price_minor' => 5000,
            'currency' => 'KES',
            'county' => 'Nairobi',
            'status' => 'active',
        ]);

        for ($index = 0; $index < $count; $index++) {
            $bid = Bid::create([
                'product_id' => $product->id,
                'buyer_id' => $buyer->id,
                'seller_id' => $seller->id,
                'status' => 'accepted',
            ]);
            Transaction::create([
                'bid_id' => $bid->id,
                'product_id' => $product->id,
                'buyer_id' => $buyer->id,
                'seller_id' => $seller->id,
                'quantity' => 2,
                'unit' => 'kg',
                'unit_price_minor' => 5000 + $index * 100,
                'total_minor' => (5000 + $index * 100) * 2,
                'currency' => 'KES',
                'status' => 'completed',
                'payment_status' => 'succeeded',
                'escrow_status' => 'released',
                'delivered_at' => now()->subDays($index),
            ]);
        }
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
}

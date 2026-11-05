<?php

namespace Tests\Feature;

use App\Models\AnalyticsSnapshot;
use App\Models\PolicyPartner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyInsightsAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_an_active_partner_key_can_access_coarse_aggregates(): void
    {
        $secret = 'flp_' . bin2hex(random_bytes(32));
        $partner = PolicyPartner::create([
            'name' => 'County planning office',
            'counties' => ['nairobi'],
            'key_hash' => hash('sha256', $secret),
        ]);
        AnalyticsSnapshot::create([
            'type' => 'market_baseline',
            'snapshot_key' => hash('sha256', 'nairobi-maize'),
            'dimensions' => ['county' => 'nairobi', 'crop' => 'maize', 'unit' => 'kg', 'currency' => 'KES'],
            'payload' => [
                'sample_size' => 5,
                'price_median_minor' => 4500,
                'price_p25_minor' => 4300,
                'price_p75_minor' => 4700,
                'completed_orders_30d' => 5,
                'completed_orders_previous_30d' => 3,
                'demand_change_percent' => 66.7,
            ],
            'computed_at' => now(),
        ]);
        AnalyticsSnapshot::create([
            'type' => 'market_baseline',
            'snapshot_key' => hash('sha256', 'nakuru-maize'),
            'dimensions' => ['county' => 'nakuru', 'crop' => 'maize', 'unit' => 'kg', 'currency' => 'KES'],
            'payload' => [
                'sample_size' => 6,
                'price_median_minor' => 4400,
                'price_p25_minor' => 4200,
                'price_p75_minor' => 4600,
                'completed_orders_30d' => 6,
                'completed_orders_previous_30d' => 4,
                'demand_change_percent' => 50,
            ],
            'computed_at' => now(),
        ]);

        $this->getJson('/api/v1/policy/insights')
            ->assertUnauthorized();
        $this->getJson('/api/v1/policy/insights', ['X-Partner-Key' => $secret])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.completed_trade_count', 5)
            ->assertJsonMissingPath('data.0.user_id')
            ->assertJsonMissingPath('data.0.transaction_id');

        $partner->forceFill(['revoked_at' => now()])->save();
        $this->getJson('/api/v1/policy/insights', ['X-Partner-Key' => $secret])
            ->assertUnauthorized();
    }
}

<?php

namespace Tests\Feature;

use App\Models\AnalyticsSnapshot;
use App\Models\FarmerImpactObservation;
use App\Models\PolicyPartner;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\ImpactReportService;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImpactReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_farmer_can_submit_consented_observations_and_only_view_own_records(): void
    {
        $farmer = $this->farmer('nairobi');
        $other = $this->farmer('nairobi');
        $payload = [
            'reporting_period' => now()->startOfMonth()->toDateString(),
            'baseline_monthly_income_minor' => 10000,
            'current_monthly_income_minor' => 11500,
            'baseline_post_harvest_loss_percent' => 20,
            'current_post_harvest_loss_percent' => 15,
            'consent' => true,
        ];

        $this->withToken($this->token($farmer))
            ->postJson('/api/v1/impact/observations', $payload, ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()
            ->assertJsonPath('data.county', 'nairobi');
        $this->withToken($this->token($other))->getJson('/api/v1/impact/observations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('farmer_impact_observations', 1);
    }

    public function test_partner_report_is_county_scoped_thresholded_and_labels_targets_as_projections(): void
    {
        for ($index = 0; $index < 5; $index++) {
            $this->observation('nairobi');
        }
        for ($index = 0; $index < 4; $index++) {
            $this->observation('nakuru');
        }
        app(ImpactReportService::class)->refresh();

        $secret = 'flp_' . bin2hex(random_bytes(32));
        PolicyPartner::create([
            'name' => 'Nairobi NGO',
            'counties' => ['nairobi', 'nakuru'],
            'key_hash' => hash('sha256', $secret),
        ]);

        $this->getJson('/api/v1/policy/impact/report', ['X-Partner-Key' => $secret])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.region.county', 'nairobi')
            ->assertJsonPath('data.0.metrics.farmer_sample_size', 5)
            ->assertJsonPath('data.0.metrics.observed_income_change_percent', 15)
            ->assertJsonPath('data.0.metrics.income_target_percent.minimum', 15)
            ->assertJsonPath('data.0.metrics.income_target_percent.maximum', 20)
            ->assertJsonPath('data.0.metrics.post_harvest_loss_reduction_percentage_points', 5)
            ->assertJsonMissingPath('data.0.metrics.farmer_id')
            ->assertJsonPath('meta.minimum_farmer_cohort', 5);
    }

    public function test_impact_snapshots_do_not_expose_counties_outside_partner_scope(): void
    {
        AnalyticsSnapshot::create([
            'type' => 'sdg_impact_report',
            'snapshot_key' => hash('sha256', 'sdg_impact_report:nairobi'),
            'dimensions' => ['county' => 'nairobi', 'period' => 'latest_farmer_observation_12m'],
            'payload' => ['farmer_sample_size' => 5, 'computed_at' => now()->toIso8601String()],
            'computed_at' => now(),
        ]);
        AnalyticsSnapshot::create([
            'type' => 'sdg_impact_report',
            'snapshot_key' => hash('sha256', 'sdg_impact_report:nakuru'),
            'dimensions' => ['county' => 'nakuru', 'period' => 'latest_farmer_observation_12m'],
            'payload' => ['farmer_sample_size' => 5, 'computed_at' => now()->toIso8601String()],
            'computed_at' => now(),
        ]);
        $secret = 'flp_' . bin2hex(random_bytes(32));
        PolicyPartner::create([
            'name' => 'Nairobi county office',
            'counties' => ['nairobi'],
            'key_hash' => hash('sha256', $secret),
        ]);

        $this->getJson('/api/v1/policy/impact', ['X-Partner-Key' => $secret])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.region.county', 'nairobi');
    }

    private function observation(string $county): void
    {
        $farmer = $this->farmer($county);
        FarmerImpactObservation::create([
            'farmer_id' => $farmer->id,
            'reporting_period' => now()->startOfMonth()->toDateString(),
            'county' => $county,
            'baseline_monthly_income_minor' => 10000,
            'current_monthly_income_minor' => 11500,
            'baseline_post_harvest_loss_percent' => 20,
            'current_post_harvest_loss_percent' => 15,
            'currency' => 'KES',
            'consented_at' => now(),
        ]);
    }

    private function farmer(string $county): User
    {
        $farmer = User::create([
            'name' => 'Impact reporter',
            'phone' => '2547' . random_int(10000000, 99999999),
            'password' => bcrypt('impact-test-password'),
            'role' => 'farmer',
        ]);
        UserProfile::create(['user_id' => $farmer->id, 'county' => $county]);

        return $farmer;
    }

    private function token(User $user): string
    {
        return JWT::encode([
            'iss' => (string) config('app.url'),
            'sub' => (string) $user->id,
            'iat' => time(),
            'nbf' => time(),
            'exp' => time() + 3600,
            'jti' => (string) Str::uuid(),
        ], (string) config('jwt.secret'), 'HS256');
    }
}

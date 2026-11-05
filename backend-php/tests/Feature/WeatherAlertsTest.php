<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\NotificationService;
use App\Services\WeatherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeatherAlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_rain_temperature_and_pest_risks_create_alerts_for_users_in_the_affected_county(): void
    {
        $affected = $this->userWithCounty('Nakuru');
        $this->userWithCounty('Kiambu');
        $service = app(WeatherService::class);
        $notifications = app(NotificationService::class);

        $result = $service->ingest([
            'provider_event_id' => 'weather-event-001',
            'county' => 'Nakuru',
            'observed_at' => now()->toIso8601String(),
            'rainfall_mm' => 120,
            'temperature_c' => 41,
            'pest_risks' => ['fall armyworm'],
        ]);

        $this->assertCount(3, $result['alerts']);
        foreach ($result['alerts'] as $alert) {
            $this->assertSame(1, $notifications->dispatchWeatherAlert($alert['id']));
        }

        $this->assertDatabaseCount('weather_alerts', 3);
        $this->assertDatabaseCount('farm_notifications', 3);
        $this->assertSame(
            0,
            \Illuminate\Support\Facades\DB::table('farm_notifications')
                ->where('user_id', $this->userIdForCounty('Kiambu'))
                ->count(),
        );
        $this->assertSame($affected->id, \Illuminate\Support\Facades\DB::table('farm_notifications')->value('user_id'));
    }

    public function test_duplicate_weather_event_does_not_duplicate_alerts_or_notifications(): void
    {
        $this->userWithCounty('Nakuru');
        $payload = [
            'provider_event_id' => 'weather-event-duplicate',
            'county' => 'Nakuru',
            'observed_at' => now()->toIso8601String(),
            'rainfall_mm' => 80,
            'pest_risks' => [],
        ];
        $weather = app(WeatherService::class);
        $notifications = app(NotificationService::class);

        $first = $weather->ingest($payload);
        foreach ($first['alerts'] as $alert) {
            $notifications->dispatchWeatherAlert($alert['id']);
        }
        $second = $weather->ingest($payload);

        $this->assertCount(1, $first['alerts']);
        $this->assertCount(0, $second['alerts']);
        $this->assertDatabaseCount('weather_alerts', 1);
        $this->assertDatabaseCount('farm_notifications', 1);
    }

    public function test_weather_feed_rejects_stale_observations(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(WeatherService::class)->ingest([
            'provider_event_id' => 'weather-event-stale',
            'county' => 'Nakuru',
            'observed_at' => now()->subDays(10)->toIso8601String(),
            'rainfall_mm' => 80,
        ]);
    }

    public function test_weather_webhook_authenticates_feed_and_dispatches_local_alerts(): void
    {
        $this->userWithCounty('Nakuru');
        config(['services.weather.webhook_secret' => str_repeat('w', 40)]);
        $payload = [
            'provider_event_id' => 'webhook-weather-001',
            'county' => 'Nakuru',
            'observed_at' => now()->toIso8601String(),
            'rainfall_mm' => 70,
            'temperature_c' => 36,
            'pest_risks' => [],
        ];

        $this->postJson('/api/v1/webhooks/weather', $payload)
            ->assertUnauthorized();

        $this->withHeader('X-FarmLink-Weather-Secret', str_repeat('w', 40))
            ->postJson('/api/v1/webhooks/weather', $payload)
            ->assertAccepted()
            ->assertJsonPath('data.alerts_created', 2)
            ->assertJsonPath('data.notifications_created', 2);
    }

    private function userWithCounty(string $county): User
    {
        $user = User::create([
            'name' => 'Pilot User',
            'phone' => '2547' . random_int(10000000, 99999999),
            'password' => bcrypt('test-password-123'),
            'role' => 'farmer',
        ]);
        $user->profile()->create(['county' => $county]);
        return $user;
    }

    private function userIdForCounty(string $county): string
    {
        return (string) \Illuminate\Support\Facades\DB::table('user_profiles')
            ->where('county', $county)
            ->value('user_id');
    }
}

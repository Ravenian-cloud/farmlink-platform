<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class WeatherService
{
    public function ingest(array $feed): array
    {
        $validated = validator($feed, [
            'provider_event_id' => ['required', 'string', 'max:180'],
            'county' => ['required', 'string', 'max:100'],
            'observed_at' => ['required', 'date'],
            'rainfall_mm' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'temperature_c' => ['nullable', 'numeric', 'between:-80,70'],
            'pest_risks' => ['nullable', 'array', 'max:20'],
            'pest_risks.*' => ['string', 'max:120'],
        ])->validate();

        $observedAt = Carbon::parse($validated['observed_at']);
        if ($observedAt->isFuture() || $observedAt->lt(now()->subDays(7))) {
            throw ValidationException::withMessages([
                'observed_at' => 'Weather observations must be no more than seven days old and cannot be future-dated.',
            ]);
        }

        $alerts = [];
        $rainfall = (float) ($validated['rainfall_mm'] ?? 0);
        $temperature = isset($validated['temperature_c']) ? (float) $validated['temperature_c'] : null;
        if ($rainfall >= 50) {
            $alerts[] = ['hazard' => 'heavy_rain', 'severity' => $rainfall >= 100 ? 'high' : 'moderate'];
        }
        if ($temperature !== null && $temperature >= 35) {
            $alerts[] = ['hazard' => 'high_temperature', 'severity' => $temperature >= 40 ? 'high' : 'moderate'];
        }
        $pestRisks = array_values(array_unique($validated['pest_risks'] ?? []));
        if ($pestRisks !== []) {
            $alerts[] = ['hazard' => 'pest_risk', 'severity' => 'moderate', 'risks' => $pestRisks];
        }

        $created = [];
        foreach ($alerts as $alert) {
            $alertId = (string) \Illuminate\Support\Str::uuid();
            $inserted = DB::table('weather_alerts')->insertOrIgnore([
                'id' => $alertId,
                'provider_event_id' => $validated['provider_event_id'],
                'county' => $validated['county'],
                'hazard' => $alert['hazard'],
                'severity' => $alert['severity'],
                'details' => json_encode([
                    'rainfall_mm' => $validated['rainfall_mm'] ?? null,
                    'temperature_c' => $validated['temperature_c'] ?? null,
                    'risks' => $alert['risks'] ?? [],
                ], JSON_THROW_ON_ERROR),
                'observed_at' => $observedAt,
                'created_at' => now(),
            ]);
            if ($inserted === 1) {
                $created[] = ['id' => $alertId, ...$alert];
            }
        }

        if ($created === [] && $alerts !== []) {
            Log::info('Duplicate weather provider event ignored.', [
                'provider_event_id' => $validated['provider_event_id'],
            ]);
        }

        return [
            'provider_event_id' => $validated['provider_event_id'],
            'alerts' => $created,
        ];
    }
}

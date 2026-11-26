<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WeatherController extends Controller
{
    public function current(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);
        $apiKey = (string) config('services.weather.api_key');
        $useLocalOpenMeteo = $apiKey === '' && app()->environment('local');
        if ($apiKey === '' && !$useLocalOpenMeteo) {
            return response()->json(['message' => 'Live weather needs a WeatherAPI key. Add it as WEATHER_API_KEY in backend-php/.env.'], 503);
        }

        $latitude = round((float) $validated['latitude'], 2);
        $longitude = round((float) $validated['longitude'], 2);
        $cacheKey = sprintf('weather.current.%0.2f.%0.2f', $latitude, $longitude);

        try {
            $weather = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($apiKey, $latitude, $longitude): array {
                return $apiKey !== ''
                    ? $this->fetchWeatherApi($apiKey, $latitude, $longitude)
                    : $this->fetchLocalOpenMeteo($latitude, $longitude);
            });
        } catch (Throwable $error) {
            Log::warning('Live weather request failed.', [
                'exception' => $error::class,
                'details' => $apiKey === '' ? $error->getMessage() : 'WeatherAPI request failed.',
            ]);
            return response()->json(['message' => 'Live weather is temporarily unavailable. Please try again shortly.'], 502);
        }

        $current = $weather['current'];
        $rainChance = collect($weather['next_hours'])->max('rain_chance_percent') ?? 0;
        $expectedRain = collect($weather['next_hours'])->sum('precipitation_mm');
        $recommendations = [];
        if ($rainChance >= 60 || $expectedRain >= 2) {
            $recommendations[] = 'Rain is likely in the next six hours. Consider delaying spraying and protecting harvested produce.';
        }
        if ((float) $current['temperature_c'] >= 35) {
            $recommendations[] = 'High heat is expected. Check crop water needs and avoid heat-sensitive field work during the hottest hours.';
        }
        if ((float) $current['wind_kph'] >= 35) {
            $recommendations[] = 'Strong winds are possible. Secure lightweight covers and equipment.';
        }
        if ((float) $current['humidity_percent'] >= 85 && (float) $current['precipitation_mm'] < 0.1) {
            $recommendations[] = 'Humidity is high. Monitor crops for fungal disease and allow airflow where possible.';
        }
        if ($recommendations === []) {
            $recommendations[] = 'No immediate weather concerns stand out. Check the hourly outlook before planning field work.';
        }
        $weather['analysis'] = $recommendations;

        return response()->json(['data' => $weather]);
    }

    private function fetchWeatherApi(string $apiKey, float $latitude, float $longitude): array
    {
        $payload = Http::connectTimeout(3)->timeout(8)->get('https://api.weatherapi.com/v1/forecast.json', [
            'key' => $apiKey,
            'q' => $latitude . ',' . $longitude,
            'days' => 3,
            'aqi' => 'no',
            'alerts' => 'yes',
        ])->throw()->json();
        if (!isset($payload['current'], $payload['location'])) {
            throw new \UnexpectedValueException('Weather provider returned an incomplete response.');
        }

        $current = $payload['current'];
        $futureHours = collect($payload['forecast']['forecastday'] ?? [])
            ->flatMap(static fn (array $day): array => $day['hour'] ?? [])
            ->filter(static fn (array $hour): bool => (int) ($hour['time_epoch'] ?? 0) >= time())
            ->take(6)
            ->map(static fn (array $hour): array => [
                'time' => $hour['time'] ?? null,
                'temperature_c' => $hour['temp_c'] ?? null,
                'precipitation_mm' => $hour['precip_mm'] ?? 0,
                'rain_chance_percent' => $hour['chance_of_rain'] ?? 0,
                'condition' => $hour['condition']['text'] ?? 'Unknown',
            ])->values();

        $daily = collect($payload['forecast']['forecastday'] ?? [])
            ->take(3)
            ->map(static fn (array $day): array => [
                'date' => $day['date'] ?? null,
                'minimum_c' => $day['day']['mintemp_c'] ?? null,
                'maximum_c' => $day['day']['maxtemp_c'] ?? null,
                'rain_chance_percent' => $day['day']['daily_chance_of_rain'] ?? 0,
                'precipitation_mm' => $day['day']['totalprecip_mm'] ?? 0,
                'condition' => $day['day']['condition']['text'] ?? 'Unknown',
            ])->values();

        return [
            'location' => [
                'name' => $payload['location']['name'] ?? 'Your location',
                'region' => $payload['location']['region'] ?? '',
                'country' => $payload['location']['country'] ?? '',
                'local_time' => $payload['location']['localtime'] ?? null,
                'timezone' => $payload['location']['tz_id'] ?? null,
            ],
            'current' => [
                'updated_at' => $current['last_updated'] ?? null,
                'temperature_c' => $current['temp_c'] ?? null,
                'feels_like_c' => $current['feelslike_c'] ?? null,
                'condition' => $current['condition']['text'] ?? 'Unknown',
                'condition_code' => $current['condition']['code'] ?? null,
                'humidity_percent' => $current['humidity'] ?? null,
                'precipitation_mm' => $current['precip_mm'] ?? 0,
                'wind_kph' => $current['wind_kph'] ?? null,
                'wind_gust_kph' => $current['gust_kph'] ?? null,
                'uv_index' => $current['uv'] ?? null,
                'is_day' => (bool) ($current['is_day'] ?? false),
            ],
            'next_hours' => $futureHours,
            'next_days' => $daily,
            'alerts' => collect($payload['alerts']['alert'] ?? [])->take(5)->map(static fn (array $alert): array => [
                'headline' => $alert['headline'] ?? 'Weather alert',
                'severity' => $alert['severity'] ?? null,
                'effective' => $alert['effective'] ?? null,
                'expires' => $alert['expires'] ?? null,
                'description' => $alert['desc'] ?? '',
            ])->values(),
            'source' => 'WeatherAPI.com',
            'source_url' => 'https://www.weatherapi.com/',
        ];
    }

    private function fetchLocalOpenMeteo(float $latitude, float $longitude): array
    {
        $payload = Http::connectTimeout(3)->timeout(8)->get('https://api.open-meteo.com/v1/forecast', [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,weather_code,wind_speed_10m,wind_gusts_10m,is_day',
            'hourly' => 'temperature_2m,precipitation_probability,precipitation,weather_code',
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,precipitation_sum',
            'forecast_days' => 3,
            'timezone' => 'auto',
            'wind_speed_unit' => 'kmh',
        ])->throw()->json();

        if (!isset($payload['current'], $payload['hourly'], $payload['daily'])) {
            throw new \UnexpectedValueException('Weather provider returned an incomplete response.');
        }

        $current = $payload['current'];
        $currentTime = (string) ($current['time'] ?? '');
        $hourly = $payload['hourly'];
        $futureIndices = collect($hourly['time'] ?? [])->keys()
            ->filter(static fn (int $index): bool => (string) ($hourly['time'][$index] ?? '') >= $currentTime)
            ->take(6);
        $futureHours = $futureIndices->map(fn (int $index): array => [
            'time' => $hourly['time'][$index] ?? null,
            'temperature_c' => $hourly['temperature_2m'][$index] ?? null,
            'precipitation_mm' => $hourly['precipitation'][$index] ?? 0,
            'rain_chance_percent' => $hourly['precipitation_probability'][$index] ?? 0,
            'condition' => $this->openMeteoCondition(isset($hourly['weather_code'][$index]) ? (int) $hourly['weather_code'][$index] : null),
        ])->values();

        $daily = collect($payload['daily']['time'] ?? [])->take(3)->map(fn (string $date, int $index): array => [
            'date' => $date,
            'minimum_c' => $payload['daily']['temperature_2m_min'][$index] ?? null,
            'maximum_c' => $payload['daily']['temperature_2m_max'][$index] ?? null,
            'rain_chance_percent' => $payload['daily']['precipitation_probability_max'][$index] ?? 0,
            'precipitation_mm' => $payload['daily']['precipitation_sum'][$index] ?? 0,
            'condition' => $this->openMeteoCondition(isset($payload['daily']['weather_code'][$index]) ? (int) $payload['daily']['weather_code'][$index] : null),
        ])->values();

        return [
            'location' => [
                'name' => 'Your location',
                'region' => $payload['timezone'] ?? '',
                'country' => '',
                'local_time' => $currentTime,
                'timezone' => $payload['timezone'] ?? null,
            ],
            'current' => [
                'updated_at' => $currentTime,
                'temperature_c' => $current['temperature_2m'] ?? null,
                'feels_like_c' => $current['apparent_temperature'] ?? null,
                'condition' => $this->openMeteoCondition(isset($current['weather_code']) ? (int) $current['weather_code'] : null),
                'condition_code' => $current['weather_code'] ?? null,
                'humidity_percent' => $current['relative_humidity_2m'] ?? null,
                'precipitation_mm' => $current['precipitation'] ?? 0,
                'wind_kph' => $current['wind_speed_10m'] ?? null,
                'wind_gust_kph' => $current['wind_gusts_10m'] ?? null,
                'uv_index' => null,
                'is_day' => (bool) ($current['is_day'] ?? false),
            ],
            'next_hours' => $futureHours,
            'next_days' => $daily,
            'alerts' => [],
            'source' => 'Open-Meteo',
            'source_url' => 'https://open-meteo.com/',
        ];
    }

    private function openMeteoCondition(?int $code): string
    {
        return match ($code) {
            0 => 'Clear sky',
            1 => 'Mainly clear',
            2 => 'Partly cloudy',
            3 => 'Overcast',
            45, 48 => 'Fog',
            51, 53, 55 => 'Drizzle',
            56, 57 => 'Freezing drizzle',
            61, 63, 65 => 'Rain',
            66, 67 => 'Freezing rain',
            71, 73, 75 => 'Snowfall',
            77 => 'Snow grains',
            80, 81, 82 => 'Rain showers',
            85, 86 => 'Snow showers',
            95, 96, 99 => 'Thunderstorm',
            default => 'Unknown',
        };
    }
}

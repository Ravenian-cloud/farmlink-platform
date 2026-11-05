<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class NotificationService
{
    public function dispatchWeatherAlert(string $alertId): int
    {
        $alert = DB::table('weather_alerts')->where('id', $alertId)->first();
        if (!$alert) {
            return 0;
        }

        $userIds = DB::table('user_profiles')
            ->join('users', 'users.id', '=', 'user_profiles.user_id')
            ->where('user_profiles.county', $alert->county)
            ->where('users.deleted_at', null)
            ->pluck('users.id');

        $created = 0;
        foreach ($userIds as $userId) {
            $created += DB::table('farm_notifications')->insertOrIgnore([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'user_id' => $userId,
                'weather_alert_id' => $alert->id,
                'type' => $alert->hazard,
                'title' => $this->titleFor($alert->hazard),
                'body' => $this->bodyFor($alert),
                'created_at' => now(),
            ]);
        }

        return $created;
    }

    private function titleFor(string $hazard): string
    {
        return match ($hazard) {
            'heavy_rain' => 'Heavy rainfall alert',
            'high_temperature' => 'High temperature alert',
            'pest_risk' => 'Pest risk alert',
            default => 'FarmLink weather alert',
        };
    }

    private function bodyFor(object $alert): string
    {
        $details = json_decode($alert->details, true, 512, JSON_THROW_ON_ERROR);

        return match ($alert->hazard) {
            'heavy_rain' => 'Heavy rainfall is forecast or observed in ' . $alert->county . '.',
            'high_temperature' => 'High temperatures are reported in ' . $alert->county . '.',
            'pest_risk' => 'Reported pest risks (' . implode(', ', $details['risks'] ?? []) . ') affect ' . $alert->county . '.',
            default => 'A weather alert affects ' . $alert->county . '.',
        };
    }
}

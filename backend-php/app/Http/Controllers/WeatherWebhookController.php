<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use App\Services\WeatherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WeatherWebhookController extends Controller
{
    public function __invoke(Request $request, WeatherService $weather, NotificationService $notifications): JsonResponse
    {
        $secret = (string) config('services.weather.webhook_secret');
        $provided = (string) $request->header('X-FarmLink-Weather-Secret', '');
        if (strlen($secret) < 32 || !hash_equals($secret, $provided)) {
            Log::warning('Rejected unauthenticated weather feed.');
            return response()->json(['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Valid provider authentication is required.']], 401);
        }

        $result = $weather->ingest($request->all());
        $dispatched = 0;
        foreach ($result['alerts'] as $alert) {
            $dispatched += $notifications->dispatchWeatherAlert($alert['id']);
        }

        return response()->json([
            'data' => [
                'provider_event_id' => $result['provider_event_id'],
                'alerts_created' => count($result['alerts']),
                'notifications_created' => $dispatched,
            ],
        ], 202);
    }
}

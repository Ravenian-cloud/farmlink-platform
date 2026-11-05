<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'county' => ['sometimes', 'string', 'max:100'],
            'crop' => ['sometimes', 'string', 'max:100'],
        ]);
        $county = isset($validated['county']) ? mb_strtolower(trim($validated['county'])) : null;
        $crop = isset($validated['crop']) ? mb_strtolower(trim($validated['crop'])) : null;

        $snapshots = AnalyticsSnapshot::query()
            ->where('type', 'market_baseline')
            ->when($county !== null, fn ($query) => $query->where('dimensions->county', $county))
            ->when($crop !== null, fn ($query) => $query->where('dimensions->crop', $crop))
            ->orderBy('dimensions->county')
            ->orderBy('dimensions->crop')
            ->get()
            ->map(static fn (AnalyticsSnapshot $snapshot): array => [
                'dimensions' => $snapshot->dimensions,
                'metrics' => $snapshot->payload,
                'computed_at' => $snapshot->computed_at,
            ]);

        return response()->json([
            'data' => $snapshots,
            'meta' => [
                'count' => $snapshots->count(),
                'privacy_threshold' => 5,
                'classification' => 'historical_market_baseline_not_personalized_advice',
            ],
        ]);
    }

    public function advanced(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'county' => ['sometimes', 'string', 'max:100'],
            'crop' => ['sometimes', 'string', 'max:100'],
        ]);
        $county = isset($validated['county']) ? mb_strtolower(trim($validated['county'])) : null;
        $crop = isset($validated['crop']) ? mb_strtolower(trim($validated['crop'])) : null;
        $snapshots = AnalyticsSnapshot::query()
            ->where('type', 'market_baseline')
            ->when($county !== null, fn ($query) => $query->where('dimensions->county', $county))
            ->when($crop !== null, fn ($query) => $query->where('dimensions->crop', $crop))
            ->orderBy('dimensions->county')
            ->orderBy('dimensions->crop')
            ->get()
            ->map(static function (AnalyticsSnapshot $snapshot): array {
                $payload = $snapshot->payload;
                $median = $payload['price_median_minor'];

                return [
                    'dimensions' => $snapshot->dimensions,
                    'sample_size' => $payload['sample_size'],
                    'median_price_minor' => $median,
                    'price_interquartile_range_minor' => $payload['price_p75_minor'] - $payload['price_p25_minor'],
                    'price_p25_minor' => $payload['price_p25_minor'],
                    'price_p75_minor' => $payload['price_p75_minor'],
                    'completed_orders_30d' => $payload['completed_orders_30d'],
                    'completed_orders_previous_30d' => $payload['completed_orders_previous_30d'],
                    'demand_change_percent' => $payload['demand_change_percent'],
                    'computed_at' => $snapshot->computed_at,
                    'limitations' => 'Historical aggregate, not a forecast or price recommendation.',
                ];
            });

        return response()->json(['data' => $snapshots, 'meta' => ['access' => 'premium']]);
    }
}

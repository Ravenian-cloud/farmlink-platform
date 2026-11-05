<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PolicyInsightsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $partner = $request->attributes->get('policy_partner');
        abort_unless($partner && $partner->counties !== [], 403, 'The partner has no approved county data scope.');
        $snapshots = AnalyticsSnapshot::query()
            ->where('type', 'market_baseline')
            ->where('computed_at', '>=', now()->subDays(10))
            ->whereIn('dimensions->county', $partner->counties)
            ->orderBy('dimensions->county')
            ->orderBy('dimensions->crop')
            ->get()
            ->map(static function (AnalyticsSnapshot $snapshot): array {
                $dimensions = $snapshot->dimensions;
                $metrics = $snapshot->payload;

                return [
                    'region' => ['county' => $dimensions['county']],
                    'commodity' => $dimensions['crop'],
                    'unit' => $dimensions['unit'],
                    'currency' => $dimensions['currency'],
                    'period_days' => 30,
                    'completed_trade_count' => $metrics['sample_size'],
                    'median_price_minor' => $metrics['price_median_minor'],
                    'p25_price_minor' => $metrics['price_p25_minor'],
                    'p75_price_minor' => $metrics['price_p75_minor'],
                    'completed_order_change_percent' => $metrics['demand_change_percent'],
                    'updated_at' => Carbon::parse($snapshot->computed_at)->toIso8601String(),
                ];
            });

        return response()->json([
            'data' => $snapshots,
            'meta' => [
                'count' => $snapshots->count(),
                'minimum_cohort_size' => 5,
                'county_scope' => $partner->counties,
                'privacy' => 'Aggregated by county and commodity; no user, listing, or transaction identifiers are included.',
                'limitations' => 'Historical completed-trade aggregates only; not a forecast or policy recommendation.',
            ],
        ]);
    }
}

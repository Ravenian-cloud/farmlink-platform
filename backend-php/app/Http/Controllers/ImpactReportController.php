<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImpactReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $partner = $request->attributes->get('policy_partner');
        abort_unless($partner && $partner->counties !== [], 403, 'The partner has no approved county data scope.');

        $snapshots = AnalyticsSnapshot::query()
            ->where('type', 'sdg_impact_report')
            ->where('computed_at', '>=', now()->subDays(10))
            ->whereIn('dimensions->county', $partner->counties)
            ->orderBy('dimensions->county')
            ->get()
            ->map(static function (AnalyticsSnapshot $snapshot): array {
                return [
                    'region' => $snapshot->dimensions,
                    'metrics' => $snapshot->payload,
                ];
            })
            ->values();

        return response()->json([
            'data' => $snapshots,
            'meta' => [
                'generated_at' => $snapshots->max(static fn (array $report): string => $report['metrics']['computed_at'] ?? ''),
                'county_scope' => $partner->counties,
                'minimum_farmer_cohort' => 5,
                'privacy' => 'Only county aggregates with at least five reporting farmers are included. Farmer-level observations and identifiers are not returned.',
                'limitations' => 'Income and post-harvest outcomes are farmer-reported and observational. The 15–20% income range is a target scenario, not an observed result or causal impact estimate.',
            ],
        ]);
    }
}

<?php

namespace App\Services;

use App\Models\AnalyticsSnapshot;
use Illuminate\Support\Facades\DB;

class ImpactReportService
{
    private const MINIMUM_FARMER_COHORT = 5;

    public function refresh(): int
    {
        $now = now();
        $latestByFarmer = DB::table('farmer_impact_observations')
            ->select('farmer_id')
            ->selectRaw('MAX(reporting_period) AS reporting_period')
            ->where('reporting_period', '>=', $now->copy()->subYear()->toDateString())
            ->groupBy('farmer_id');

        $observations = DB::table('farmer_impact_observations as observations')
            ->joinSub($latestByFarmer, 'latest', function ($join): void {
                $join->on('latest.farmer_id', '=', 'observations.farmer_id')
                    ->on('latest.reporting_period', '=', 'observations.reporting_period');
            })
            ->select([
                'observations.farmer_id',
                'observations.county',
                'observations.baseline_monthly_income_minor',
                'observations.current_monthly_income_minor',
                'observations.baseline_post_harvest_loss_percent',
                'observations.current_post_harvest_loss_percent',
                'observations.currency',
            ])
            ->get()
            ->groupBy(static fn (object $row): string => mb_strtolower(trim((string) $row->county)));

        $countyTrades = DB::table('transactions')
            ->join('products', 'products.id', '=', 'transactions.product_id')
            ->where('transactions.status', 'completed')
            ->where('transactions.payment_status', 'succeeded')
            ->where('products.category', 'produce')
            ->whereNotNull('transactions.delivered_at')
            ->where('transactions.delivered_at', '>=', $now->copy()->subYear())
            ->selectRaw('LOWER(products.county) AS county, COUNT(*) AS trade_count')
            ->groupByRaw('LOWER(products.county)')
            ->pluck('trade_count', 'county');

        $snapshots = [];
        foreach ($observations as $county => $farmers) {
            if ($county === '' || $farmers->count() < self::MINIMUM_FARMER_COHORT) {
                continue;
            }

            $baselineIncome = $farmers->sum(static fn (object $row): int => (int) $row->baseline_monthly_income_minor);
            $currentIncome = $farmers->sum(static fn (object $row): int => (int) $row->current_monthly_income_minor);
            $baselineLoss = $farmers->avg(static fn (object $row): float => (float) $row->baseline_post_harvest_loss_percent);
            $currentLoss = $farmers->avg(static fn (object $row): float => (float) $row->current_post_harvest_loss_percent);
            $incomeChange = $baselineIncome === 0
                ? null
                : round((($currentIncome - $baselineIncome) / $baselineIncome) * 100, 1);
            $averageBaselineIncome = (int) round($baselineIncome / $farmers->count());
            $averageCurrentIncome = (int) round($currentIncome / $farmers->count());
            $tradeCount = (int) ($countyTrades->get($county) ?? 0);
            $dimensions = ['county' => $county, 'period' => 'latest_farmer_observation_12m'];
            $payload = [
                'farmer_sample_size' => $farmers->count(),
                'currency' => (string) $farmers->first()->currency,
                'average_baseline_monthly_income_minor' => $averageBaselineIncome,
                'average_current_monthly_income_minor' => $averageCurrentIncome,
                'observed_income_change_percent' => $incomeChange,
                'income_target_percent' => ['minimum' => 15, 'maximum' => 20],
                'projected_target_monthly_income_minor' => [
                    'minimum' => (int) round($averageBaselineIncome * 1.15),
                    'maximum' => (int) round($averageBaselineIncome * 1.20),
                ],
                'average_baseline_post_harvest_loss_percent' => round($baselineLoss, 1),
                'average_current_post_harvest_loss_percent' => round($currentLoss, 1),
                'post_harvest_loss_reduction_percentage_points' => round($baselineLoss - $currentLoss, 1),
                'completed_produce_trades_12m' => $tradeCount >= self::MINIMUM_FARMER_COHORT ? $tradeCount : null,
                'sdg_alignment' => ['SDG 1', 'SDG 2', 'SDG 9'],
                'methodology' => 'Farmer-reported paired monthly income and post-harvest-loss observations; target projections are scenarios, not measured outcomes or causal estimates.',
                'computed_at' => $now->toIso8601String(),
            ];
            $snapshots[] = [
                'type' => 'sdg_impact_report',
                'snapshot_key' => hash('sha256', 'sdg_impact_report:' . json_encode($dimensions, JSON_THROW_ON_ERROR)),
                'dimensions' => json_encode($dimensions, JSON_THROW_ON_ERROR),
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'computed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($snapshots): void {
            AnalyticsSnapshot::query()->where('type', 'sdg_impact_report')->delete();
            if ($snapshots !== []) {
                DB::table('analytics_snapshots')->insert($snapshots);
            }
        });

        return count($snapshots);
    }
}

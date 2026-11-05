<?php

namespace App\Services;

use App\Models\AnalyticsSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyticsSnapshotService
{
    private const MINIMUM_COHORT_SIZE = 5;

    public function refresh(): int
    {
        $now = now();
        $currentWindowStart = $now->copy()->subDays(30);
        $previousWindowStart = $now->copy()->subDays(60);
        $cohorts = [];

        DB::table('transactions')
            ->join('products', 'products.id', '=', 'transactions.product_id')
            ->where('transactions.status', 'completed')
            ->where('transactions.payment_status', 'succeeded')
            ->where('products.category', 'produce')
            ->whereNotNull('products.crop')
            ->whereNotNull('transactions.delivered_at')
            ->where('transactions.delivered_at', '>=', $previousWindowStart)
            ->where('transactions.delivered_at', '<=', $now)
            ->select([
                'transactions.id as cursor_id',
                'transactions.unit_price_minor',
                'transactions.currency',
                'transactions.unit',
                'transactions.delivered_at',
                'products.crop',
                'products.county',
            ])
            ->orderBy('transactions.id')
            ->chunkById(1000, function ($transactions) use (&$cohorts, $currentWindowStart): void {
                foreach ($transactions as $transaction) {
                    $crop = mb_strtolower(trim((string) $transaction->crop));
                    $county = mb_strtolower(trim((string) ($transaction->county ?? '')));
                    $dimensions = [
                        'crop' => $crop !== '' ? $crop : 'unknown',
                        'county' => $county !== '' ? $county : 'unknown',
                        'unit' => mb_strtolower(trim((string) $transaction->unit)),
                        'currency' => strtoupper((string) $transaction->currency),
                    ];
                    $key = hash('sha256', json_encode($dimensions, JSON_THROW_ON_ERROR));
                    $cohorts[$key] ??= [
                        'dimensions' => $dimensions,
                        'current_prices' => [],
                        'current_orders' => 0,
                        'previous_orders' => 0,
                    ];

                    $deliveredAt = Carbon::parse($transaction->delivered_at);
                    if ($deliveredAt->greaterThanOrEqualTo($currentWindowStart)) {
                        $cohorts[$key]['current_prices'][] = (int) $transaction->unit_price_minor;
                        $cohorts[$key]['current_orders']++;
                    } else {
                        $cohorts[$key]['previous_orders']++;
                    }
                }
            }, 'transactions.id', 'cursor_id');

        $snapshots = [];
        foreach ($cohorts as $cohort) {
            $prices = $cohort['current_prices'];
            if (count($prices) < self::MINIMUM_COHORT_SIZE) {
                continue;
            }

            sort($prices, SORT_NUMERIC);
            $dimensions = $cohort['dimensions'];
            $previousOrders = $cohort['previous_orders'];
            $currentOrders = $cohort['current_orders'];
            $changePercent = $previousOrders === 0
                ? null
                : round((($currentOrders - $previousOrders) / $previousOrders) * 100, 1);

            $snapshots[] = [
                'type' => 'market_baseline',
                'snapshot_key' => hash('sha256', json_encode($dimensions, JSON_THROW_ON_ERROR)),
                'dimensions' => json_encode($dimensions, JSON_THROW_ON_ERROR),
                'payload' => json_encode([
                    'sample_size' => count($prices),
                    'price_median_minor' => $this->percentile($prices, 0.5),
                    'price_p25_minor' => $this->percentile($prices, 0.25),
                    'price_p75_minor' => $this->percentile($prices, 0.75),
                    'completed_orders_30d' => $currentOrders,
                    'completed_orders_previous_30d' => $previousOrders,
                    'demand_change_percent' => $changePercent,
                    'baseline_demand_next_30d' => $currentOrders,
                    'baseline_method' => 'last_30_days_volume_not_a_trained_forecast',
                ], JSON_THROW_ON_ERROR),
                'computed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($snapshots): void {
            AnalyticsSnapshot::query()->where('type', 'market_baseline')->delete();
            if ($snapshots !== []) {
                DB::table('analytics_snapshots')->insert($snapshots);
            }
        });

        return count($snapshots);
    }

    /**
     * @param  list<int>  $sortedValues
     */
    private function percentile(array $sortedValues, float $percentile): int
    {
        $index = ($percentile * (count($sortedValues) - 1));
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        $value = $sortedValues[$lower] + (($sortedValues[$upper] - $sortedValues[$lower]) * ($index - $lower));

        return (int) round($value);
    }
}

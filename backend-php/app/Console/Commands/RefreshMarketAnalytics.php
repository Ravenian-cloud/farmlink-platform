<?php

namespace App\Console\Commands;

use App\Services\AnalyticsSnapshotService;
use Illuminate\Console\Command;

class RefreshMarketAnalytics extends Command
{
    protected $signature = 'analytics:refresh-market';

    protected $description = 'Refresh privacy-thresholded market price and demand baselines';

    public function handle(AnalyticsSnapshotService $analytics): int
    {
        $count = $analytics->refresh();
        $this->info("Refreshed {$count} market baseline cohorts.");

        return self::SUCCESS;
    }
}

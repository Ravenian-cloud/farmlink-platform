<?php

namespace App\Console\Commands;

use App\Services\ImpactReportService;
use Illuminate\Console\Command;

class RefreshImpactReports extends Command
{
    protected $signature = 'farmlink:refresh-impact-reports';

    protected $description = 'Refresh county-level, privacy-thresholded SDG impact reports';

    public function handle(ImpactReportService $reports): int
    {
        $count = $reports->refresh();
        $this->info("Refreshed {$count} SDG impact report cohorts.");

        return self::SUCCESS;
    }
}

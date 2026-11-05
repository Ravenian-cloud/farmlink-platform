<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('farmlink:status', function (): void {
    $this->info('FarmLink API console is ready.');
})->purpose('Check that the FarmLink console is bootstrapped.');

Schedule::command('analytics:refresh-market')
    ->dailyAt('02:15')
    ->withoutOverlapping();

Schedule::command('farmlink:refresh-impact-reports')
    ->dailyAt('02:45')
    ->withoutOverlapping();

<?php

use App\Console\Commands\ExpireLoyaltyPointsCommand;
use App\Console\Commands\NotifyUpcomingDeliveriesCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(NotifyUpcomingDeliveriesCommand::class)
    ->dailyAt('08:00')
    ->withoutOverlapping();

Schedule::command(ExpireLoyaltyPointsCommand::class)
    ->dailyAt('02:00')
    ->withoutOverlapping();
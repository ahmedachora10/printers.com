<?php

use App\Console\Commands\NotifyUpcomingDeliveriesCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// تذكير الموظفين بمواعيد التسليم المستحقة غداً — صباح كل يوم قبل بدء العمل.
Schedule::command(NotifyUpcomingDeliveriesCommand::class)
    ->dailyAt('08:00')
    ->withoutOverlapping();

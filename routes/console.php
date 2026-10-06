<?php

use App\Console\Commands\ExpireLoyaltyPointsCommand;
use App\Console\Commands\NotifyUpcomingDeliveriesCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(NotifyUpcomingDeliveriesCommand::class)
    ->dailyAt('08:00')
    ->withoutOverlapping();

Schedule::command(ExpireLoyaltyPointsCommand::class)
    ->dailyAt('02:00')
    ->withoutOverlapping();

// الاستضافة المشتركة بلا عامل دائم: الطابور يُفرَّغ كل دقيقة على cron الجدولة نفسه.
Schedule::command('queue:work --stop-when-empty --max-time=600')
    ->everyMinute()
    ->withoutOverlapping(15)
    ->runInBackground();

// ملفات ZIP التي بناها BuildMediaZipJob تُحذف بعد 24 ساعة.
Schedule::call(function () {
    $disk = Storage::disk('local');
    foreach ($disk->allFiles('zips') as $file) {
        if ($disk->lastModified($file) < now()->subDay()->getTimestamp()) {
            $disk->deleteDirectory(dirname($file));
        }
    }
})->hourly()->name('prune-media-zips');

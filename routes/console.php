<?php

use App\Console\LinkStreamSchedule;
use Illuminate\Console\Scheduling\Schedule as ScheduleConfig;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

LinkStreamSchedule::register(app(ScheduleConfig::class));

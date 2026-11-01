<?php

use App\Models\ActivityLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Drops activity log entries older than ActivityLog::KEEP_MONTHS (needs `php artisan schedule:run` every minute).
Schedule::command('model:prune', ['--model' => [ActivityLog::class]])->daily();

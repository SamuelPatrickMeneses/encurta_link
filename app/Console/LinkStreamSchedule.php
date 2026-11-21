<?php

declare(strict_types=1);

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;

final class LinkStreamSchedule
{
    public static function register(Schedule $schedule): void
    {
        $interval = config('links.stream.consume_interval_minutes');

        if (! is_int($interval) || $interval < 1 || $interval > 59) {
            Log::error(
                'LINK_STREAM_CONSUME_INTERVAL_MINUTES tem de ser um inteiro entre 1 e 59; '
                . 'o consumidor não foi agendado.',
                ['interval' => $interval],
            );

            return;
        }

        $schedule->command('links:consume --once')
            ->cron(sprintf('*/%d * * * *', $interval))
            ->withoutOverlapping(5);
    }
}

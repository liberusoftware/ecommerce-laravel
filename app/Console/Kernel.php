<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Clear out expired shipping quotes daily so the table doesn't grow unbounded.
        $schedule->command('shipping:prune-quotes')->daily();

        // Meta applies its own review after upload, so local status goes stale
        // without a read-back.
        $schedule->command('facebook:reconcile-catalog')->hourly();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}

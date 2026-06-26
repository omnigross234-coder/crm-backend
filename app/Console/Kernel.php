protected function schedule(Schedule $schedule): void
{
    $schedule->command('db:backup')
             ->dailyAt('02:00')
             ->withoutOverlapping();
}
<?php

namespace App\Console\Commands;

use App\Models\Followup;
use Illuminate\Console\Command;

class SendFollowupReminders extends Command
{
    protected $signature = 'followups:send-reminders';

    protected $description = 'Marks follow-ups due within the next 10 minutes for in-app display.';

    public function handle(): int
    {
        $now = now();
        $readyBefore = $now->copy()->addMinutes(10);
        $ready = 0;

        Followup::query()
            ->where('status', 'pending')
            ->whereNull('reminder_ready_at')
            ->whereNotNull('next_followup_datetime')
            ->whereBetween('next_followup_datetime', [$now, $readyBefore])
            ->chunkById(100, function ($followups) use (&$ready): void {
                foreach ($followups as $followup) {
                    // Atomically claim each reminder so a retried or
                    // overlapping scheduler run cannot create duplicates.
                    $claimed = Followup::query()
                        ->whereKey($followup->id)
                        ->whereNull('reminder_ready_at')
                        ->update(['reminder_ready_at' => now()]);

                    if ($claimed === 1) {
                        $ready++;
                    }
                }
            });

        $this->info("Marked {$ready} follow-up reminder(s) ready for in-app display.");

        return self::SUCCESS;
    }
}

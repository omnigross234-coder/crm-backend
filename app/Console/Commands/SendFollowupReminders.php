<?php

namespace App\Console\Commands;

use App\Models\Followup;
use App\Models\User;
use App\Notifications\FollowupReminderNotification;
use App\Services\FirebasePushService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class SendFollowupReminders extends Command
{
    protected $signature = 'followups:send-reminders';

    protected $description = 'Send reminders for follow-ups that are due within the next 10 minutes.';

    public function handle(): int
    {
        $now = now();
        $reminderWindowEnd = $now->copy()->addMinutes(10);
        $sent = 0;
        $skipped = 0;

        Followup::query()
            ->with(['lead:id,name,phone,email,company,assigned_to', 'user:id,name,email,role,status'])
            ->where('status', 'pending')
            ->where('reminder_sent', false)
            ->whereNotNull('next_followup_datetime')
            ->whereBetween('next_followup_datetime', [$now, $reminderWindowEnd])
            ->chunkById(100, function ($followups) use (&$sent, &$skipped): void {
                foreach ($followups as $followup) {
                    $recipients = $this->recipientsFor($followup);

                    if ($recipients->isEmpty()) {
                        $skipped++;

                        continue;
                    }

                    $recipients->each(function (User $user) use ($followup): void {
                        $notification = new FollowupReminderNotification($followup);
                        $data = $notification->toArray($user);

                        $user->notify($notification);

                        app(FirebasePushService::class)->sendToUser(
                            $user,
                            $data['title'],
                            $data['message'],
                            [
                                'type' => $data['type'],
                                'followup_id' => $data['followup_id'],
                                'lead_id' => $data['lead_id'],
                            ]
                        );
                    });

                    $followup->forceFill(['reminder_sent' => true])->save();
                    $sent += $recipients->count();
                }
            });

        $this->info("Sent {$sent} follow-up reminder(s). Skipped {$skipped} follow-up(s) without a user.");

        return self::SUCCESS;
    }

    private function recipientsFor(Followup $followup): Collection
    {
        $recipients = collect();

        if ($followup->user && $followup->user->status !== 'inactive') {
            $recipients->push($followup->user);
        }

        if ($followup->lead?->assigned_to) {
            $assignedSalesUser = User::query()
                ->whereKey($followup->lead->assigned_to)
                ->where('status', '!=', 'inactive')
                ->first();

            if ($assignedSalesUser) {
                $recipients->push($assignedSalesUser);
            }
        }

        User::query()
            ->where('role', 'admin')
            ->where('status', '!=', 'inactive')
            ->get()
            ->each(fn (User $admin) => $recipients->push($admin));

        return $recipients->unique('id')->values();
    }
}

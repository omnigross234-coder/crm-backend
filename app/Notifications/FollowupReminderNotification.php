<?php

namespace App\Notifications;

use App\Models\Followup;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FollowupReminderNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly Followup $followup)
    {
        $this->followup->loadMissing('lead:id,name,phone,email,company');
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lead = $this->followup->lead;
        $time = optional($this->followup->next_followup_datetime)->format('d M Y, h:i A');

        return (new MailMessage)
            ->subject('Follow-up reminder')
            ->line("You have a follow-up scheduled in about 10 minutes for {$lead?->name}.")
            ->line("Scheduled time: {$time}")
            ->line("Note: {$this->followup->note}");
    }

    public function toArray(object $notifiable): array
    {
        $lead = $this->followup->lead;

        return [
            'type' => 'followup_reminder',
            'title' => 'Follow-up reminder',
            'followup_id' => $this->followup->id,
            'lead_id' => $this->followup->lead_id,
            'lead_name' => $lead?->name,
            'lead_phone' => $lead?->phone,
            'lead_email' => $lead?->email,
            'lead_company' => $lead?->company,
            'note' => $this->followup->note,
            'scheduled_at' => optional($this->followup->next_followup_datetime)->toISOString(),
            'message' => "Follow-up with {$lead?->name} is due in about 10 minutes.",
        ];
    }
}

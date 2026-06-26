<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Twilio\Http\CurlClient;
use Twilio\Rest\Client;

class TwilioService
{
    private Client $client;

    public function __construct()
    {
        if (app()->environment('local')) {
            $curlClient = new CurlClient([
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_SSL_VERIFYPEER => 0,
            ]);
            $this->client = new Client(
                config('services.twilio.sid'),
                config('services.twilio.token'),
                null,
                null,
                $curlClient,
            );
        } else {
            $this->client = new Client(
                config('services.twilio.sid'),
                config('services.twilio.token'),
            );
        }
    }

    public function sendSms(string $to, string $body): bool
    {
        try {
            $this->client->messages->create($to, [
                'from' => config('services.twilio.from'),
                'body' => $body,
            ]);
            return true;
        } catch (\Throwable $e) {
            Log::error('Twilio SMS failed', [
                'to'    => $to,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function sendWhatsapp(string $to, string $body): bool
    {
        try {
            $whatsappTo = str_starts_with($to, 'whatsapp:')
                ? $to
                : "whatsapp:{$to}";

            $this->client->messages->create($whatsappTo, [
                'from' => config('services.twilio.whatsapp_from'),
                'body' => $body,
            ]);
            return true;
        } catch (\Throwable $e) {
            Log::error('Twilio WhatsApp failed', [
                'to'    => $to,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
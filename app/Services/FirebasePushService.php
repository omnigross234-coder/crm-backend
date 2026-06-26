<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FirebasePushService
{
    public function sendToUser(User $user, string $title, string $body, array $data = []): bool
    {
        if (! $user->fcm_token) {
            return false;
        }

        $projectId = config('services.firebase.project_id');
        $accessToken = $this->accessToken();

        if (! $projectId || ! $accessToken) {
            Log::warning('Firebase push skipped because Firebase server credentials are missing.');

            return false;
        }

        $response = $this->http()->withToken($accessToken)->post(
            "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send",
            [
                'message' => [
                    'token' => $user->fcm_token,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                    ],
                  // AFTER
                  'data' => empty($data) ? new \stdClass() : $this->stringData($data),
                    'android' => [
                        'priority' => 'HIGH',
                    ],
                ],
            ]
        );

        if ($response->failed()) {
            if ($this->isUnregisteredToken($response->json())) {
                $user->forceFill(['fcm_token' => null])->save();
            }

            Log::warning('Firebase push failed.', [
                'user_id' => $user->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    private function isUnregisteredToken(?array $response): bool
    {
        $details = data_get($response, 'error.details', []);

        foreach ($details as $detail) {
            if (data_get($detail, 'errorCode') === 'UNREGISTERED') {
                return true;
            }
        }

        return false;
    }

    private function accessToken(): ?string
    {
        $credentialsPath = config('services.firebase.credentials');

        if (! $credentialsPath || ! is_file($credentialsPath)) {
            return null;
        }

        $credentials = json_decode(file_get_contents($credentialsPath), true);

        if (! is_array($credentials) || empty($credentials['client_email']) || empty($credentials['private_key'])) {
            return null;
        }

        $now = time();
        $assertion = $this->jwt([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ], $credentials['private_key']);

        $response = $this->http()->asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]);

        if ($response->failed()) {
            Log::warning('Firebase access token request failed.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return $response->json('access_token');
    }

    private function jwt(array $payload, string $privateKey): string
    {
        $segments = [
            $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64UrlEncode(json_encode($payload)),
        ];

        openssl_sign(implode('.', $segments), $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function http(): PendingRequest
    {
        $request = Http::acceptJson();

        if (! config('services.firebase.verify_ssl')) {
            $request = $request->withoutVerifying();
        }

        return $request;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function stringData(array $data): array
    {
        return collect($data)
            ->mapWithKeys(fn ($value, $key) => [(string) $key => (string) $value])
            ->all();
    }
}

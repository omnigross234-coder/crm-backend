<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AppSettingController extends Controller
{
    private const POST_CALL_SMS_KEY = 'post_call_sms';
    private const POST_CALL_WHATSAPP_KEY = 'post_call_whatsapp';
    private const DEFAULT_SMS_MESSAGE = 'Thank you for your time. We will contact you shortly.';
    private const DEFAULT_WHATSAPP_MESSAGE = 'Thank you for your time. We will contact you shortly.';

    public function showSmsMessage(Request $request)
    {
        $clientId = Auth::user()->client_id;

        // BelongsToClient global scope already filters this, but we're explicit here
        // since we want a default fallback rather than a 404 for a brand-new client.
        $smsSetting = AppSetting::withoutGlobalScope('client')
            ->where('client_id', $clientId)
            ->where('key', self::POST_CALL_SMS_KEY)
            ->first();
        $whatsAppSetting = AppSetting::withoutGlobalScope('client')
            ->where('client_id', $clientId)
            ->where('key', self::POST_CALL_WHATSAPP_KEY)
            ->first();

        $smsMessage = $smsSetting->value ?? self::DEFAULT_SMS_MESSAGE;

        return response()->json([
            'success' => true,
            'data' => [
                // `message` is retained for older mobile builds.
                'message' => $smsMessage,
                'sms_message' => $smsMessage,
                'whatsapp_message' => $whatsAppSetting->value ?? self::DEFAULT_WHATSAPP_MESSAGE,
            ],
        ]);
    }

    public function updateSmsMessage(Request $request)
    {
        $data = $request->validate([
            'message' => 'sometimes|required|string|max:1000',
            'sms_message' => 'sometimes|required|string|max:1000',
            'whatsapp_message' => 'sometimes|required|string|max:1000',
        ]);

        $clientId = Auth::user()->client_id;

        $smsMessage = $data['sms_message'] ?? $data['message'] ?? null;
        if ($smsMessage !== null) {
            AppSetting::withoutGlobalScope('client')->updateOrCreate(
                ['client_id' => $clientId, 'key' => self::POST_CALL_SMS_KEY],
                ['value' => $smsMessage]
            );
        }
        if (array_key_exists('whatsapp_message', $data)) {
            AppSetting::withoutGlobalScope('client')->updateOrCreate(
                ['client_id' => $clientId, 'key' => self::POST_CALL_WHATSAPP_KEY],
                ['value' => $data['whatsapp_message']]
            );
        }

        $smsSetting = AppSetting::withoutGlobalScope('client')
            ->where('client_id', $clientId)->where('key', self::POST_CALL_SMS_KEY)->first();
        $whatsAppSetting = AppSetting::withoutGlobalScope('client')
            ->where('client_id', $clientId)->where('key', self::POST_CALL_WHATSAPP_KEY)->first();

        return response()->json([
            'success' => true,
            'data' => [
                'message' => $smsSetting->value ?? self::DEFAULT_SMS_MESSAGE,
                'sms_message' => $smsSetting->value ?? self::DEFAULT_SMS_MESSAGE,
                'whatsapp_message' => $whatsAppSetting->value ?? self::DEFAULT_WHATSAPP_MESSAGE,
            ],
        ]);
    }
}

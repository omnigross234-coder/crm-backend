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

    /**
     * FLT-F04 (Phase 1 audit): the mobile app has always sent these 7
     * fields alongside sms_message/whatsapp_message on every save, but
     * this controller silently accepted and discarded them - every value
     * the user set (incoming/outgoing/missed toggles, unknown-number-only,
     * reminder gap, SIM slot) reverted to whatever each Flutter screen's
     * own local default happened to be on next load, and the two Flutter
     * consumers (SmsSettingsController, CallLogService) disagreed with
     * each other about what that default should be for a value the
     * backend never actually returned. Persisting all 9 fields here, with
     * one shared default set, fixes both problems at once: nothing is
     * silently dropped, and neither Flutter consumer's fallback logic is
     * ever exercised against a real client again.
     *
     * Defaults below match the app's own pre-existing assumptions
     * (SmsSettingsController's initial .obs values / clearForm(), and
     * CallLogService._getMessageSettings()'s own fallback) - not invented
     * here, just made authoritative in one place instead of guessed
     * independently by each client.
     */
    private const BOOL_KEYS = [
        'incoming_enabled' => ['key' => 'post_call_incoming_enabled', 'default' => true],
        'outgoing_enabled' => ['key' => 'post_call_outgoing_enabled', 'default' => true],
        'missed_enabled' => ['key' => 'post_call_missed_enabled', 'default' => true],
        'sms_enabled' => ['key' => 'post_call_sms_enabled', 'default' => true],
        'unknown_only' => ['key' => 'post_call_unknown_only', 'default' => false],
    ];
    private const GAP_KEY = 'post_call_gap';
    private const DEFAULT_GAP = '5_days';
    private const SIM_SLOT_KEY = 'post_call_sim_slot';
    private const DEFAULT_SIM_SLOT = 1;

    public function showSmsMessage(Request $request)
    {
        $clientId = Auth::user()->client_id;

        return response()->json([
            'success' => true,
            'data' => $this->loadSettings($clientId),
        ]);
    }

    public function updateSmsMessage(Request $request)
    {
        $data = $request->validate([
            'message' => 'sometimes|required|string|max:1000',
            'sms_message' => 'sometimes|required|string|max:1000',
            'whatsapp_message' => 'sometimes|required|string|max:1000',
            'gap' => 'sometimes|required|string|in:1_hour,1_day,2_days,5_days,10_days,20_days,30_days,never',
            'incoming_enabled' => 'sometimes|required|boolean',
            'outgoing_enabled' => 'sometimes|required|boolean',
            'missed_enabled' => 'sometimes|required|boolean',
            'sms_enabled' => 'sometimes|required|boolean',
            'unknown_only' => 'sometimes|required|boolean',
            'sim_slot' => 'sometimes|required|integer|in:1,2',
        ]);

        $clientId = Auth::user()->client_id;

        $smsMessage = $data['sms_message'] ?? $data['message'] ?? null;
        if ($smsMessage !== null) {
            $this->putSetting($clientId, self::POST_CALL_SMS_KEY, $smsMessage);
        }
        if (array_key_exists('whatsapp_message', $data)) {
            $this->putSetting($clientId, self::POST_CALL_WHATSAPP_KEY, $data['whatsapp_message']);
        }
        if (array_key_exists('gap', $data)) {
            $this->putSetting($clientId, self::GAP_KEY, $data['gap']);
        }
        if (array_key_exists('sim_slot', $data)) {
            $this->putSetting($clientId, self::SIM_SLOT_KEY, (string) $data['sim_slot']);
        }
        foreach (self::BOOL_KEYS as $field => $meta) {
            if (array_key_exists($field, $data)) {
                $this->putSetting($clientId, $meta['key'], $data[$field] ? '1' : '0');
            }
        }

        return response()->json([
            'success' => true,
            'data' => $this->loadSettings($clientId),
        ]);
    }

    private function loadSettings(int $clientId): array
    {
        $settings = AppSetting::withoutGlobalScope('client')
            ->where('client_id', $clientId)
            ->whereIn('key', array_merge(
                [self::POST_CALL_SMS_KEY, self::POST_CALL_WHATSAPP_KEY, self::GAP_KEY, self::SIM_SLOT_KEY],
                array_column(self::BOOL_KEYS, 'key')
            ))
            ->pluck('value', 'key');

        $smsMessage = $settings[self::POST_CALL_SMS_KEY] ?? self::DEFAULT_SMS_MESSAGE;

        $result = [
            // `message` is retained for older mobile builds.
            'message' => $smsMessage,
            'sms_message' => $smsMessage,
            'whatsapp_message' => $settings[self::POST_CALL_WHATSAPP_KEY] ?? self::DEFAULT_WHATSAPP_MESSAGE,
            'gap' => $settings[self::GAP_KEY] ?? self::DEFAULT_GAP,
            'sim_slot' => isset($settings[self::SIM_SLOT_KEY]) ? (int) $settings[self::SIM_SLOT_KEY] : self::DEFAULT_SIM_SLOT,
        ];

        foreach (self::BOOL_KEYS as $field => $meta) {
            $result[$field] = isset($settings[$meta['key']])
                ? $settings[$meta['key']] === '1'
                : $meta['default'];
        }

        return $result;
    }

    private function putSetting(int $clientId, string $key, string $value): void
    {
        AppSetting::withoutGlobalScope('client')->updateOrCreate(
            ['client_id' => $clientId, 'key' => $key],
            ['value' => $value]
        );
    }
}

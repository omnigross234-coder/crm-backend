<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeadFieldSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LeadFieldSettingController extends Controller
{
    private const DEFAULT_FIELDS = [
        ['field_key' => 'call_notes', 'label' => 'Call Notes', 'field_type' => 'feature', 'active' => true, 'required' => false, 'is_custom' => false, 'sort_order' => 1],
        ['field_key' => 'followup_notes', 'label' => 'Follow-up Notes', 'field_type' => 'feature', 'active' => true, 'required' => false, 'is_custom' => false, 'sort_order' => 2],
        ['field_key' => 'company', 'label' => 'Company', 'field_type' => 'text', 'active' => true, 'required' => false, 'is_custom' => false, 'sort_order' => 5],
        ['field_key' => 'address', 'label' => 'Address', 'field_type' => 'textarea', 'active' => true, 'required' => false, 'is_custom' => false, 'sort_order' => 10],
        ['field_key' => 'city', 'label' => 'City', 'field_type' => 'text', 'active' => true, 'required' => false, 'is_custom' => false, 'sort_order' => 20],
        ['field_key' => 'state', 'label' => 'State', 'field_type' => 'text', 'active' => true, 'required' => false, 'is_custom' => false, 'sort_order' => 30],
        ['field_key' => 'country', 'label' => 'Country', 'field_type' => 'text', 'active' => true, 'required' => false, 'is_custom' => false, 'sort_order' => 40],
        ['field_key' => 'pin_code', 'label' => 'PIN Code', 'field_type' => 'text', 'active' => true, 'required' => false, 'is_custom' => false, 'sort_order' => 50],
        ['field_key' => 'referral_name', 'label' => 'Referral Name', 'field_type' => 'text', 'active' => true, 'required' => false, 'is_custom' => false, 'sort_order' => 60],
        ['field_key' => 'industry_type', 'label' => 'Industry Type', 'field_type' => 'text', 'active' => true, 'required' => false, 'is_custom' => false, 'sort_order' => 70],
        ['field_key' => 'business_type', 'label' => 'Business Type', 'field_type' => 'text', 'active' => true, 'required' => false, 'is_custom' => false, 'sort_order' => 80],
        ['field_key' => 'product_service_interested_in', 'label' => 'Product/Service Interested In', 'field_type' => 'text', 'active' => true, 'required' => false, 'is_custom' => false, 'sort_order' => 90],
        ['field_key' => 'budget', 'label' => 'Budget', 'field_type' => 'text', 'active' => true, 'required' => false, 'is_custom' => false, 'sort_order' => 100],
        ['field_key' => 'documents', 'label' => 'Documents', 'field_type' => 'textarea', 'active' => true, 'required' => false, 'is_custom' => false, 'sort_order' => 110],
        ['field_key' => 'annual_turnover', 'label' => 'Annual Turnover', 'field_type' => 'text', 'active' => true, 'required' => false, 'is_custom' => false, 'sort_order' => 120],
        ['field_key' => 'gst_number', 'label' => 'GST Number', 'field_type' => 'text', 'active' => true, 'required' => false, 'is_custom' => false, 'sort_order' => 130],
        ['field_key' => 'requirement', 'label' => 'Requirement', 'field_type' => 'textarea', 'active' => true, 'required' => false, 'is_custom' => false, 'sort_order' => 140],
    ];

    // GET /api/lead-field-settings
    public function index(Request $request)
    {
        $clientId = $this->clientId($request);

        foreach (self::DEFAULT_FIELDS as $field) {
            LeadFieldSetting::firstOrCreate(
                ['client_id' => $clientId, 'field_key' => $field['field_key']],
                $field,
            );
        }

        $settings = $this->formatSettings(
            LeadFieldSetting::where('client_id', $clientId)->orderBy('sort_order')->get()
        );

        return response()->json([
            'success' => true,
            'message' => 'Lead field settings fetched successfully.',
            'data' => $settings,
        ]);
    }

    // PUT /api/lead-field-settings
    public function update(Request $request)
    {
        $items = $request->input('fields', []);

        if (! is_array($items) || empty($items)) {
            return response()->json([
                'success' => false,
                'message' => 'Fields array is required.',
            ], 422);
        }

        $validator = Validator::make(['items' => $items], [
            'items' => ['required', 'array'],
            'items.*.key' => ['required', 'string'],
            'items.*.active' => ['required', 'boolean'],
            'items.*.required' => ['required', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'data' => $validator->errors(),
            ], 422);
        }

        $clientId = $this->clientId($request);

        foreach ($items as $item) {
            LeadFieldSetting::where('client_id', $clientId)
                ->where('field_key', $item['key'])
                ->update([
                    'active' => $item['active'],
                    'required' => $item['active'] ? $item['required'] : false,
                ]);
        }

        $settings = $this->formatSettings(
            LeadFieldSetting::where('client_id', $clientId)->orderBy('sort_order')->get()
        );

        return response()->json([
            'success' => true,
            'message' => 'Lead field settings updated successfully.',
            'data' => $settings,
        ]);
    }

    // POST /api/lead-field-settings
    public function store(Request $request)
    {
        $clientId = $this->clientId($request);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(['text', 'textarea', 'number'])],
            'required' => ['required', 'boolean'],
        ]);

        $baseKey = Str::slug($data['label'], '_');
        $key = $baseKey;
        $suffix = 1;
        while (
            LeadFieldSetting::where('client_id', $clientId)
                ->where('field_key', $key)
                ->exists()
        ) {
            $key = $baseKey . '_' . (++$suffix);
        }

        $maxSort = LeadFieldSetting::where('client_id', $clientId)->max('sort_order') ?? 0;

        LeadFieldSetting::create([
            'client_id' => $clientId,
            'field_key' => $key,
            'label' => $data['label'],
            'field_type' => $data['type'],
            'active' => true,
            'required' => $data['required'],
            'is_custom' => true,
            'sort_order' => $maxSort + 1,
        ]);

        $settings = $this->formatSettings(
            LeadFieldSetting::where('client_id', $clientId)->orderBy('sort_order')->get()
        );

        return response()->json([
            'success' => true,
            'message' => 'Custom field created successfully.',
            'data' => $settings,
        ]);
    }

    // DELETE /api/lead-field-settings/{leadFieldSetting}
    public function destroy(Request $request, LeadFieldSetting $leadFieldSetting)
    {
        abort_if($leadFieldSetting->client_id !== $request->user()->client_id, 403);
        abort_unless($leadFieldSetting->is_custom, 422, 'Only custom fields can be deleted.');

        $leadFieldSetting->delete();

        return response()->json([
            'success' => true,
            'message' => 'Custom field deleted successfully.',
        ]);
    }

    // Maps DB column names to the shape the frontend expects (key, type, label, active, required)
    private function formatSettings($settings)
    {
        return $settings->map(fn ($s) => [
            'key' => $s->field_key,
            'label' => $s->label,
            'type' => $s->field_type,
            'active' => $s->active,
            'required' => $s->required,
            'is_custom' => $s->is_custom,
        ]);
    }

    private function clientId(Request $request): int
    {
        $clientId = $request->user()?->client_id;

        abort_if(! $clientId, 403, 'A client account is required to manage lead fields.');

        return (int) $clientId;
    }
}

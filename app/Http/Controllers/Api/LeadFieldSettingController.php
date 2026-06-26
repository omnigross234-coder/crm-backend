<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeadFieldSetting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LeadFieldSettingController extends Controller
{
    private const FIELD_KEYS = [
        'address',
        'city',
        'state',
        'country',
        'pin_code',
        'referral_name',
        'industry_type',
        'business_type',
        'product_service_interested_in',
        'budget',
        'documents',
        'annual_turnover',
        'gst_number',
        'requirement',
    ];

    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Lead field settings retrieved.',
            'data' => LeadFieldSetting::orderBy('sort_order')->get(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $fieldKeys = LeadFieldSetting::pluck('field_key')->all();
        $validated = $request->validate([
            'fields' => ['required', 'array'],
            'fields.*.key' => ['required', Rule::in($fieldKeys)],
            'fields.*.active' => ['required', 'boolean'],
            'fields.*.required' => ['required', 'boolean'],
        ]);

        foreach ($validated['fields'] as $field) {
            LeadFieldSetting::where('field_key', $field['key'])->update([
                'active' => $field['active'],
                'required' => $field['active'] ? $field['required'] : false,
            ]);
        }

        return $this->index();
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:80'],
           'field_type' => ['required', Rule::in(['text', 'textarea', 'number'])],
            'required' => ['nullable', 'boolean'],
        ]);

        $fieldKey = $this->makeFieldKey($validated['label']);

        if (LeadFieldSetting::where('field_key', $fieldKey)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'A field with this name already exists.',
                'data' => null,
            ], 422);
        }

        Schema::table('leads', function (Blueprint $table) use ($fieldKey, $validated) {
    if ($validated['field_type'] === 'textarea') {
        $table->text($fieldKey)->nullable();
    } elseif ($validated['field_type'] === 'number') {
        $table->decimal($fieldKey, 15, 2)->nullable();
    } else {
        $table->string($fieldKey)->nullable();
    }
});
        LeadFieldSetting::create([
            'field_key' => $fieldKey,
            'label' => $validated['label'],
            'field_type' => $validated['field_type'],
            'active' => true,
            'required' => (bool) ($validated['required'] ?? false),
            'is_custom' => true,
            'sort_order' => ((int) LeadFieldSetting::max('sort_order')) + 10,
        ]);

        return $this->index();
    }

    public function destroy(string $fieldKey): JsonResponse
    {
        $setting = LeadFieldSetting::where('field_key', $fieldKey)->first();

        if (! $setting) {
            return response()->json([
                'success' => false,
                'message' => 'Field not found.',
                'data' => null,
            ], 404);
        }

        if (Schema::hasColumn('leads', $setting->field_key)) {
            Schema::table('leads', function (Blueprint $table) use ($setting) {
                $table->dropColumn($setting->field_key);
            });
        }

        $setting->delete();

        return $this->index();
    }

    private function makeFieldKey(string $label): string
    {
        $base = Str::of($label)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->limit(45, '')
            ->toString();

        if ($base === '') {
            $base = 'field';
        }

        $key = 'custom_'.$base;
        $candidate = $key;
        $counter = 2;

        while (Schema::hasColumn('leads', $candidate) || LeadFieldSetting::where('field_key', $candidate)->exists()) {
            $candidate = $key.'_'.$counter;
            $counter++;
        }

        return $candidate;
    }
}

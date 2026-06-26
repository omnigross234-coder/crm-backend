<?php

namespace App\Http\Requests;

use App\Models\LeadFieldSetting;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class LeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $leadId = $this->route('id');

        $rules = [
            'name'     => [
                'required',
                'string',
                'min:2',
                'max:80',
                'regex:/^[\pL\s.\'-]+$/u',
            ],
            'phone'    => [
                'required',
                'string',
                'regex:/^\d{10}$/',
                Rule::unique('leads', 'phone')->ignore($leadId),
            ],
            'email'    => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('leads', 'email')->ignore($leadId),
            ],
            'company'  => ['nullable', 'string', 'max:100'],
            'source'   => ['required', Rule::in(['website', 'referral', 'social', 'cold_call', 'other'])],
            'status'   => ['required', Rule::in(['new', 'interested', 'followup', 'converted', 'closed', 'not_interested'])],
            'priority' => ['nullable', Rule::in(['hot', 'warm', 'cold'])],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'remarks'  => ['nullable', 'string', 'max:500'],
            'call_notes' => ['nullable', 'string'],
        ];

        $fields = LeadFieldSetting::where('active', true)->get();
        foreach ($fields as $field) {
            if (! Schema::hasColumn('leads', $field->field_key)) {
                continue;
            }

            if ($field->field_type === 'number') {
                $rule = $field->required ? ['required', 'numeric'] : ['nullable', 'numeric'];
            } else {
                $rule = $field->required ? ['required', 'string'] : ['nullable', 'string'];
            }

            if (! in_array($field->field_type, ['textarea', 'number'], true)) {
                $rule[] = 'max:255';
            }

            $rules[$field->field_key] = $rule;
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'name.min' => 'Name must be at least 2 characters.',
            'name.max' => 'Name must be 80 characters or less.',
            'name.regex' => 'Name can contain letters, spaces, apostrophes, hyphens, and periods only.',
            'phone.required' => 'Phone is required.',
            'phone.regex' => 'Phone must contain exactly 10 digits.',
            'phone.unique' => 'This phone number is already used by another lead.',
            'email.unique' => 'This email address is already used by another lead.',
            'company.max' => 'Company must be 100 characters or less.',
            'remarks.max' => 'Remarks must be 500 characters or less.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed.',
            'data'    => $validator->errors(),
        ], 422));
    }
}

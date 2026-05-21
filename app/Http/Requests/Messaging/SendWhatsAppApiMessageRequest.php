<?php

namespace App\Http\Requests\Messaging;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SendWhatsAppApiMessageRequest extends FormRequest
{
    private const ALLOWED_PARAMS = [
        'to', 'message', 'apikey', 'type', 'tname', 'media_url', 'media_id',
        'values', 'button_value', 'file_name', 'file', 'report_id',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->isMethod('GET')) {
            $this->merge($this->query());
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'apikey' => 'required|string',
            'to' => 'required|string',
            'type' => ['required', 'string', Rule::in(['C', 'T', 'M'])],
            'message' => 'nullable|string',
            'tname' => 'nullable|string',
            'media_url' => 'nullable|string',
            'media_id' => 'nullable|string',
            'values' => 'nullable|string',
            'button_value' => 'nullable|string',
            'file_name' => 'nullable|string',
            'file' => 'nullable|file',
            'report_id' => 'nullable|string',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $unexpected = array_diff(array_keys($this->all()), self::ALLOWED_PARAMS);
            if ($unexpected !== []) {
                $validator->errors()->add('params', 'Invalid parameter(s): '.reset($unexpected));
            }
        });
    }
}

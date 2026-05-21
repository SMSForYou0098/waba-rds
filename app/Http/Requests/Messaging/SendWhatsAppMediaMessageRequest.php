<?php

namespace App\Http\Requests\Messaging;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SendWhatsAppMediaMessageRequest extends FormRequest
{
    private const ALLOWED_PARAMS = ['to', 'apikey', 'file'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'apikey' => 'required|string',
            'to' => 'required|string',
            'file' => 'required|file',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $unexpected = array_diff(array_keys($this->query()), self::ALLOWED_PARAMS);
            if ($unexpected !== []) {
                $validator->errors()->add('params', 'Invalid parameter(s): '.reset($unexpected));
            }
        });
    }
}

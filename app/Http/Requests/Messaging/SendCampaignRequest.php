<?php

namespace App\Http\Requests\Messaging;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'user_id' => 'required|integer',
            'numbers' => 'required|array|min:1|max:100000',
            'numbers.*' => 'required|string',
            'campaign_type' => ['required', 'string', Rule::in(['template', 'Template', 'custom', 'Custom'])],
            'template_name' => ['nullable', 'string', 'max:255', Rule::requiredIf(fn () => strtolower((string) $this->input('campaign_type')) === 'template')],
            'template_category' => ['nullable', 'string', 'max:100', Rule::requiredIf(fn () => strtolower((string) $this->input('campaign_type')) === 'template')],
            'custom_text' => ['nullable', 'string', Rule::requiredIf(fn () => strtolower((string) $this->input('campaign_type')) === 'custom')],
            'header_type' => 'nullable|string|max:32',
            'header_media_url' => 'nullable|string|max:2048',
            'header_media_id' => 'nullable|string|max:255',
            'header_file_name' => 'nullable|string|max:255',
            'body_values' => 'nullable|array',
            'button_value' => 'nullable',
            'template_language' => 'nullable|string|max:32',
        ];
    }
}

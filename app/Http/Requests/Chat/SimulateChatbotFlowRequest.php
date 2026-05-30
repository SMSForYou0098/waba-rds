<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class SimulateChatbotFlowRequest extends FormRequest
{
    public function authorize(): bool
    {
        $flow = $this->route('flow');

        return $flow && $this->user()?->can('simulate', $flow);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => 'required|string|max:4096',
            'wa_id' => 'nullable|string|max:32',
            'reset' => 'nullable|boolean',
            'use_draft' => 'nullable|boolean',
        ];
    }
}

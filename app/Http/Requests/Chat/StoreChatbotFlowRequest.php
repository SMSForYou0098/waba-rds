<?php

namespace App\Http\Requests\Chat;

use App\Models\Chat\ChatbotFlowVersion;
use Illuminate\Foundation\Http\FormRequest;

class StoreChatbotFlowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ChatbotFlowVersion::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'group_id' => 'nullable|integer',
            'definition' => 'nullable|array',
        ];
    }
}

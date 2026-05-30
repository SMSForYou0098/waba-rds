<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class PublishChatbotFlowRequest extends FormRequest
{
    public function authorize(): bool
    {
        $flow = $this->route('flow');

        return $flow && $this->user()?->can('publish', $flow);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => 'nullable|string|max:500',
        ];
    }
}

<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChatbotFlowRequest extends FormRequest
{
    public function authorize(): bool
    {
        $flow = $this->route('flow');

        return $flow && $this->user()?->can('update', $flow);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $nodeTypes = config('chatbot.node_types', []);

        return [
            'definition' => 'required|array',
            'definition.nodes' => 'required|array|min:1',
            'definition.nodes.*.id' => 'required|string|max:64',
            'definition.nodes.*.type' => ['required', 'string', Rule::in($nodeTypes)],
            'definition.nodes.*.config' => 'required|array',
            'definition.edges' => 'nullable|array',
            'definition.edges.*.from' => 'required_with:definition.edges|string',
            'definition.edges.*.to' => 'nullable|string|max:64',
            'definition.edges.*.event' => 'nullable|string|max:32',
            'definition.edges.*.match' => 'nullable|array',
            'definition.edges.*.capture' => 'nullable|array',
            'definition.edges.*.capture.var' => 'nullable|string|max:64',
            'definition.nodes.*.position' => 'nullable|array',
            'definition.entry' => 'nullable|array',
            'definition.flow_id' => 'nullable|string|max:128',
            'definition.version' => 'nullable|integer|min:1',
        ];
    }
}

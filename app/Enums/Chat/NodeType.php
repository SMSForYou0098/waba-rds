<?php

namespace App\Enums\Chat;

enum NodeType: string
{
    case TriggerKeyword = 'trigger.keyword';
    case TriggerDefault = 'trigger.default';
    case TriggerRegex = 'trigger.regex';
    case MessageText = 'message.text';
    case MessageTemplate = 'message.template';
    case MessageInteractiveButtons = 'message.interactive_buttons';
    case MessageListDynamic = 'message.list_dynamic';
    case MessageListPreset = 'message.list_preset';
    case LogicAsk = 'logic.ask';
    case LogicValidate = 'logic.validate';
    case LogicCondition = 'logic.condition';
    case LogicSetVar = 'logic.set_var';
    case LogicRouteByVar = 'logic.route_by_var';
    case IntegrationApi = 'integration.api';
    case FlowSubflow = 'flow.subflow';
    case FlowEnd = 'flow.end';
    case HandoffAgent = 'handoff.agent';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function tryFromString(?string $type): ?self
    {
        return $type !== null ? self::tryFrom($type) : null;
    }
}

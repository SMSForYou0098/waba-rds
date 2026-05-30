<?php

namespace App\Policies;

use App\Enums\Chat\FlowStatus;
use App\Models\Chat\ChatbotFlowVersion;
use App\Models\User;

class ChatbotFlowPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('View Chatbot');
    }

    public function view(User $user, ChatbotFlowVersion $flow): bool
    {
        return $this->ownsFlow($user, $flow);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('View Chatbot');
    }

    public function update(User $user, ChatbotFlowVersion $flow): bool
    {
        return $this->ownsFlow($user, $flow);
    }

    public function delete(User $user, ChatbotFlowVersion $flow): bool
    {
        if (! $this->ownsFlow($user, $flow)) {
            return false;
        }

        if ($flow->is_active && $flow->status === FlowStatus::Published) {
            return false;
        }

        return true;
    }

    public function publish(User $user, ChatbotFlowVersion $flow): bool
    {
        return $this->ownsFlow($user, $flow) && $user->hasPermissionTo('View Chatbot');
    }

    public function simulate(User $user, ChatbotFlowVersion $flow): bool
    {
        return $this->ownsFlow($user, $flow);
    }

    protected function ownsFlow(User $user, ChatbotFlowVersion $flow): bool
    {
        return (int) $flow->user_id === (int) $user->id;
    }
}

<?php

namespace App\Traits\Chat;

trait ResolvesTenantChatbotUserId
{
    protected function tenantUserId(): int
    {
        return (int) auth()->id();
    }
}

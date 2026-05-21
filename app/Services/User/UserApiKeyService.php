<?php

namespace App\Services\User;

use App\Models\Auth\ApiKey;

class UserApiKeyService
{
    public function updateLatestStatus(int|string $userId, string $status = 'false'): void
    {
        $latestApiKey = ApiKey::query()
            ->where('user_id', $userId)
            ->latest()
            ->first();

        if ($latestApiKey) {
            $latestApiKey->status = $status;
            $latestApiKey->save();
        }
    }
}

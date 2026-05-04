<?php

namespace App\Services\Billing;

use App\Models\Billing\Balance;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class BalanceService
{
    public function handleConversation($reportData, $user, $reportId)
    {
        $displayPhoneNumber = $reportData['metadata']['display_phone_number'] ?? null;
        $paid = $reportData['statuses'][0]['pricing']['type'] == 'regular';
        if(!$paid) {
            Log::info('Conversation is not paid, skipping balance deduction');
            return false;
        }
        $userData = User::where('whatsapp_number', $displayPhoneNumber)
            ->with(['balance', 'pricingModel'])
            ->lockForUpdate()
            ->first();

        if (!$userData) {
            Log::error('User is null in handleConversation');
            return false;
        }

        if (!isset($reportData['statuses'][0]['conversation'])) {
            Log::warning('Conversation data missing in handleConversation');
            return false;
        }
        if ($userData) {
            $conversation = $reportData['statuses'][0]['conversation'];
            if (!isset($conversation['origin']['type'])) {
                Log::warning('Conversation origin type missing');
                return false;
            }
            $originType = $conversation['origin']['type'] . '_price';
            $price = $userData->pricingModel->$originType ?? 0;

            $balance = $userData->balance()->latest()->first()->total_credits ?? 0;
            $newBalance = $balance - $price;

            if ($newBalance >= 0) {
                $this->deductBalance($userData, $price, $newBalance, $reportId);
                return true;
            } else {
                Log::warning("Insufficient balance for user {$userData->id}. Required: {$price}, Available: {$balance}");
                return false;
            }
        }
    }

    public function deductBalance($user, $price, $newBalance, $reportId)
    {
        $balance = new Balance();
        $balance->user_id = $user->id;
        $balance->new_credit = $price;
        $balance->report_id = $reportId;
        $balance->total_credits = $newBalance;
        $balance->payment_type = 'cash';
        $balance->account_manager_id = $user->reporting_user;
        $balance->auto_deduction = 'true';
        $balance->save();
        return response()->json(['success' => true, 'message' => 'Balance deducted successfully'],200);
    }
}

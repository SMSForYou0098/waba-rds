<?php

namespace App\Services\User;

use App\Models\User;
use Illuminate\Database\QueryException;

class UserCreditValidationService
{
    /**
     * @return array{status: bool, message?: string, balance?: float}
     */
    public function checkValidUser(string $id): array
    {
        try {
            $users = User::where('id', $id)->with(['balance', 'pricingModel'])->get();
            $users->each(function ($user) {
                $user->latest_balance = $user->balance()->latest()->first();
                $user->pricing = $user->pricingModel()->latest()->first();
                unset($user->balance, $user->pricingModel);
            });

            $userBalance = $users[0]->latest_balance->total_credits ?? 0.00;
            $marketingPrice = $users[0]->pricing->marketing_price;

            if ($userBalance < $marketingPrice) {
                return [
                    'status' => false,
                    'message' => 'insufficient credits',
                    'balance' => $users[0]->latest_balance->total_credits ?? 0,
                ];
            }

            return [
                'status' => true,
                'balance' => $userBalance,
            ];
        } catch (QueryException $e) {
            return [
                'status' => false,
                'message' => 'Query Exception: '.$e->getMessage(),
            ];
        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => 'An error occurred while processing the request.'.$e->getMessage(),
            ];
        }
    }
}

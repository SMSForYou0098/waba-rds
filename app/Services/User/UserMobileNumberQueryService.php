<?php

namespace App\Services\User;

use Illuminate\Support\Facades\DB;

class UserMobileNumberQueryService
{
    /**
     * @return array{success: bool, option?: string, count?: int, phone_numbers?: list<string>, error?: string}
     */
    public function fetchByOption(string $option): array
    {
        if ($option === 'all') {
            $mobileNumbers = DB::table('users as u')
                ->where('u.status', 'active')
                ->whereNull('u.deleted_at')
                ->select('u.phone_number')
                ->join('model_has_roles as mhr', 'u.id', '=', 'mhr.model_id')
                ->join('roles as r', 'mhr.role_id', '=', 'r.id')
                ->whereIn('r.name', ['User', 'Reseller'])
                ->whereNotNull('u.phone_number')
                ->where('u.phone_number', '!=', '')
                ->distinct()
                ->pluck('phone_number')
                ->toArray();

            return [
                'success' => true,
                'option' => $option,
                'count' => count($mobileNumbers),
                'phone_numbers' => $mobileNumbers,
            ];
        }

        [$roleType, $chatbotFilter] = $this->parseOption($option);

        if (! $roleType) {
            return ['success' => false, 'error' => 'Invalid option provided'];
        }

        $query = DB::table('users as u')
            ->select('u.phone_number')
            ->join('model_has_roles as mhr', 'u.id', '=', 'mhr.model_id')
            ->join('roles as r', 'mhr.role_id', '=', 'r.id')
            ->where('r.name', $roleType)
            ->where('u.status', 'active')
            ->whereNotNull('u.phone_number')
            ->where('u.phone_number', '!=', '');

        if ($chatbotFilter === 'including_chatbot') {
            $query->whereExists(function ($subQuery) {
                $subQuery->select(DB::raw(1))
                    ->from('chatbots as cb')
                    ->whereColumn('cb.user_id', 'u.id');
            });
        } elseif ($chatbotFilter === 'excluding_chatbot') {
            $query->whereNotExists(function ($subQuery) {
                $subQuery->select(DB::raw(1))
                    ->from('chatbots as cb')
                    ->whereColumn('cb.user_id', 'u.id');
            });
        }

        $mobileNumbers = $query->pluck('phone_number')->toArray();

        return [
            'success' => true,
            'option' => $option,
            'count' => count($mobileNumbers),
            'phone_numbers' => $mobileNumbers,
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function parseOption(string $option): array
    {
        $parts = explode('_', $option);

        if (count($parts) < 2) {
            return [null, null];
        }

        $roleType = ucfirst($parts[0]);
        $filterType = implode('_', array_slice($parts, 1));

        if (! in_array($roleType, ['User', 'Reseller'], true)) {
            return [null, null];
        }

        if (! in_array($filterType, ['all', 'including_chatbot', 'excluding_chatbot'], true)) {
            return [null, null];
        }

        return [$roleType, $filterType];
    }
}

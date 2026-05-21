<?php

namespace App\Services\User;

use App\Models\Settings\UserConfig;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UserListingService
{
    /**
     * @return array{users: mixed, roles: mixed}
     */
    public function listForAuthenticatedUser(): array
    {
        $user = Auth::user();
        $isAdmin = $user->hasRole('Admin');
        $isAgent = $user->hasRole('Support Agent');
        $cacheKey = "users_list_{$user->id}_{$isAdmin}_{$isAgent}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($user, $isAdmin, $isAgent) {
            $roles = Cache::remember('roles_list', now()->addHours(1), function () {
                return DB::table('roles')->select('id', 'name')->get();
            });

            $query = DB::table('users')
                ->select([
                    'users.id',
                    'users.company_name',
                    'users.name',
                    'users.email',
                    'users.status',
                    'users.phone_number',
                    'users.whatsapp_number',
                    'users.reporting_user',
                    'users.user_billing',
                    'users.created_at',
                    'roles.name as role_name',
                    'reporting_users.name as reporting_user_name',
                    'balances.total_credits as latest_balance',
                    DB::raw('CASE WHEN chatbots.user_id IS NOT NULL THEN 1 ELSE 0 END as has_chatbot'),
                ])
                ->leftJoin('model_has_roles', 'users.id', '=', 'model_has_roles.model_id')
                ->leftJoin('roles', 'model_has_roles.role_id', '=', 'roles.id')
                ->leftJoin('users as reporting_users', 'users.reporting_user', '=', 'reporting_users.id')
                ->leftJoin(
                    DB::raw('(SELECT DISTINCT user_id,
                          FIRST_VALUE(total_credits) OVER (PARTITION BY user_id ORDER BY id DESC) as total_credits
                          FROM balances) as balances'),
                    'users.id', '=', 'balances.user_id'
                )
                ->leftJoin(
                    DB::raw('(SELECT DISTINCT user_id FROM chatbots) as chatbots'),
                    'users.id', '=', 'chatbots.user_id'
                )
                ->whereNull('users.deleted_at');

            if (! $isAdmin && ! $isAgent) {
                $query->where('users.reporting_user', $user->id);
            }

            $query->whereNotExists(function ($subQuery) {
                $subQuery->select(DB::raw(1))
                    ->from('model_has_roles')
                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->whereColumn('model_has_roles.model_id', 'users.id')
                    ->where('roles.name', 'Support Agent');
            });

            $users = $query->orderBy('users.created_at', 'desc')
                ->get()
                ->map(fn ($row) => [
                    'id' => $row->id,
                    'company_name' => $row->company_name,
                    'name' => $row->name,
                    'email' => $row->email,
                    'user_billing' => $row->user_billing,
                    'status' => $row->status,
                    'phone_number' => $row->phone_number,
                    'whatsapp_number' => $row->whatsapp_number,
                    'created_at' => $row->created_at,
                    'latest_balance' => $row->latest_balance,
                    'role_name' => $row->role_name,
                    'rp' => $row->reporting_user_name,
                    'hasChatbot' => (bool) $row->has_chatbot,
                ]);

            return [
                'users' => $users,
                'roles' => $roles,
            ];
        });
    }

    public function listWithConfig()
    {
        return UserConfig::with([
            'user' => function ($query) {
                $query->select('id', 'company_name');
            },
        ])->get();
    }

    public function editPayload(string $id): array
    {
        $allUser = User::all();
        $roles = \Spatie\Permission\Models\Role::all();
        $users = User::with('reportingUser')->where('id', $id)->get();

        $usersWithReportingUserNames = $users->map(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'company_name' => $user->company_name,
                'phone_number' => $user->phone_number,
                'password' => $user->password,
                'white_lable' => $user->white_lable,
                'two_fector_auth' => $user->two_fector_auth,
                'user_billing' => $user->user_billing,
                'ip_auth' => $user->ip_auth,
                'ip_addresses' => $user->ip_addresses,
                'role' => $user->roles->first(),
                'email_alert' => $user->email_alerts,
                'status' => $user->status,
                'whatsapp_alert' => $user->whatsapp_alerts,
                'sms_alert' => $user->text_alerts,
                'reporting_user' => $user->reportingUser,
                'branding_configuration' => $user->brandingConfiguration ? [
                    'id' => $user->brandingConfiguration->id,
                    'logo' => $user->brandingConfiguration->logo,
                    'login_bg' => $user->brandingConfiguration->login_bg,
                    'terms' => $user->brandingConfiguration->terms,
                    'privacy' => $user->brandingConfiguration->privacy,
                    'host_url' => $user->brandingConfiguration->host_url,
                    'copyright' => $user->brandingConfiguration->copyright,
                    'created_at' => $user->brandingConfiguration->created_at,
                    'updated_at' => $user->brandingConfiguration->updated_at,
                ] : null,
            ];
        });

        return [
            'user' => $usersWithReportingUserNames,
            'allUser' => $allUser,
            'roles' => $roles,
        ];
    }
}

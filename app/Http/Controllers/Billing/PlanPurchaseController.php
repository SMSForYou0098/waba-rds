<?php
namespace App\Http\Controllers\Billing;
use App\Http\Controllers\Controller;
use App\Models\Billing\Plan;
use App\Models\Billing\UserPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Carbon\Carbon;
use Spatie\Permission\Models\Role;

class PlanPurchaseController extends Controller
{
    public function __construct()
    {
        // ensure auth (sanctum / passport / session)
        $this->middleware('auth:api');
        // throttle to avoid abuse
        $this->middleware('throttle:10,1')->only('purchase');
      
        //$this->middleware('scope:purchase-plan')->only('purchase');
    }

    public function purchase(Request $request)
    {
        $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists('plans','id')],
            'payment_gateway' => 'required|string',
            'payment_reference' => 'nullable|string',
            'auto_renew' => 'sometimes|boolean',
        ]);
      
		if (!$request->user()->tokenCan('purchase-plan')) {
       	 return response()->json(['message' => 'Insufficient privileges'], 403);
        }
      
        $user = $request->user();

        // lock the plan row to avoid concurrent updates (and read the latest role_id)
        $plan = Plan::where('id', $request->plan_id)->lockForUpdate()->firstOrFail();

        if (!$plan->active) {
            return response()->json(['message' => 'Plan is not available'], 422);
        }

        // Determine plan duration - example: using plan_config or plan duration column
        $startsAt = now();
        $expiresAt = $startsAt->copy()->addDays($plan->duration_days ?? 30); // change to your logic

        DB::beginTransaction();
        try {
            // Create user plan (meta will be encrypted by model)
            $userPlan = UserPlan::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'role_id' => $plan->role_id,
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'status' => 'active',
                'auto_renew' => (bool)$request->input('auto_renew', false),
                'meta' => [
                    'gateway' => $request->payment_gateway,
                    'reference' => $request->payment_reference,
                ],
            ]);

            // Synchronously update user roles in a safe manner
            $this->applyPlanRoleToUser($user->refresh(), $plan->role_id);

            // Write audit log
            DB::table('plan_change_logs')->insert([
                'user_id' => $user->id,
                'from_plan_id' => optional($user->activePlan())->plan_id ?? null, // implement activePlan() if you want
                'to_plan_id' => $plan->id,
                'changed_by' => $user->id,
                'reason' => 'purchase',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            // dispatch async jobs (welcome email / analytics) outside transaction
            // PlanAssigned::dispatch($user, $userPlan);

            return response()->json([
                'success' => true,
                'user_plan_id' => $userPlan->id,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['message' => 'Could not complete purchase'.$e->getMessage()], 500);
        }
    }

    /**
     * Remove only plan roles then assign the new one.
     * Preserves admin/global roles that are not plan roles.
     */
    protected function applyPlanRoleToUser($user, $newRoleId)
    {
        // get role model for new role
        $newRole = Role::find($newRoleId);
        if (! $newRole) {
            throw new \RuntimeException('Role configured for plan not found');
        }

        // collect all role IDs that are mapped by plans
        $planRoleIds = DB::table('plans')->whereNotNull('role_id')->pluck('role_id')->unique()->toArray();

        // get user's current plan roles (names) that should be removed
        $rolesToRemove = $user->roles()->whereIn('id', $planRoleIds)->pluck('name')->toArray();

        if (!empty($rolesToRemove)) {
            $user->removeRole($rolesToRemove);
        }

        // assign the plan role
        $user->assignRole($newRole->name);
    }
}

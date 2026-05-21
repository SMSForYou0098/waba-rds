<?php

namespace App\Http\Controllers\Messaging;

use App\Http\Controllers\Controller;
use App\Services\Billing\OnboardingBillingSetupService;
use App\Services\Meta\EmbeddedSignupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class OnboardingController extends Controller
{
    public function complete(
        Request $request,
        EmbeddedSignupService $service,
        OnboardingBillingSetupService $billingSetup,
    ): JsonResponse {
        try {
            $request->validate(['code' => 'required|string']);

            $user = $request->user();

            $result = $service->complete(
                $user,
                $request->string('code')->toString()
            );

            if (! ($result['ok'] ?? false)) {
                return response()->json(['status' => false], 200);
            }

            $billing = $billingSetup->setup($user);

            if (! ($billing['ok'] ?? false)) {
                return response()->json([
                    'status' => false,
                ], 200);
            }

            return response()->json([
                'status'  => true,
                'message' => 'Your onboarding has been completed successfully. Demo credits have been added to your account — you can start sending messages. Please log out and log in again to refresh your account.',
            ], 200);
        } catch (Throwable $e) {
            Log::error('Onboarding failed', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['status' => false], 200);
        }
    }
}

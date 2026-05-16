<?php

namespace App\Http\Controllers\Messaging;

use App\Http\Controllers\Controller;
use App\Http\Requests\Messaging\SendCampaignRequest;
use App\Models\Campaign\Campaign;
use App\Services\Billing\CampaignPricingService;
use App\Services\Messaging\CampaignSendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MessagingController extends Controller
{
    public function __construct(
        protected CampaignPricingService $campaignPricingService,
        protected CampaignSendService $campaignSendService
    ) {}

    public function validateCampaign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|integer',
            'campaign_type' => ['required', 'string', Rule::in(['template', 'Template', 'custom', 'Custom'])],
            'numbers_count' => 'required|integer|min:1|max:100000',
            'template_name' => ['nullable', 'string', 'max:255', Rule::requiredIf(fn () => strtolower((string) $request->input('campaign_type')) === 'template')],
            'template_category' => ['nullable', 'string', 'max:100', Rule::requiredIf(fn () => strtolower((string) $request->input('campaign_type')) === 'template')],
            'custom_text' => ['nullable', 'string', Rule::requiredIf(fn () => strtolower((string) $request->input('campaign_type')) === 'custom')],
        ]);

        if ((int) $validated['user_id'] !== (int) Auth::id()) {
            return response()->json([
                'message' => 'You may only validate campaigns for your own account.',
            ], 403);
        }

        $normalizedType = strtolower($validated['campaign_type']) === 'custom' ? 'custom' : 'template';

        $estimate = $this->campaignPricingService->estimateBulkSend(
            (int) $validated['user_id'],
            (int) $validated['numbers_count'],
            $normalizedType,
            $normalizedType === 'template' ? ($validated['template_category'] ?? null) : null
        );

        $valid = $estimate['user_found']
            && $estimate['pricing_found']
            && $estimate['can_proceed'];

        $payload = [
            'valid' => $valid,
            'balance_required' => $estimate['balance_required'],
            'current_balance' => $estimate['current_balance'],
            'can_proceed' => $estimate['can_proceed'],
        ];

        if (! $valid && $estimate['message']) {
            $payload['message'] = $estimate['message'];
        }

        return response()->json(['data' => $payload, 'status' => 200]);
    }

    public function sendCampaign(SendCampaignRequest $request): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(['data' => ['message' => 'Unauthenticated.'], 'status' => 401], 401);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        return $this->campaignSendService->execute($user, $validated);
    }

    public function campaignProgress(int $campaignId): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(['data' => ['message' => 'Unauthenticated.'], 'status' => 401], 401);
        }

        $campaign = Campaign::query()->find($campaignId);
        if (! $campaign) {
            return response()->json(['data' => ['message' => 'Campaign not found.'], 'status' => 404], 404);
        }

        if ((int) $campaign->user_id !== (int) $user->id) {
            return response()->json(['data' => ['message' => 'You may only view your own campaign progress.'], 'status' => 403], 403);
        }

        $base = DB::table('campaign_reports')->where('campaign_id', $campaignId);
        $total = (clone $base)->count();
        $sent = (clone $base)->whereIn('status', ['sent', 'delivered', 'read'])->count();
        $failed = (clone $base)->where('status', 'failed')->count();
        $pending = max(0, $total - ($sent + $failed));
        $percent = $total > 0 ? round(($sent / $total) * 100, 2) : 0.0;

        return response()->json([
            'data' => [
                'campaign_id' => $campaignId,
                'total' => $total,
                'sent' => $sent,
                'failed' => $failed,
                'pending' => $pending,
                'percent' => $percent,
                'status' => $pending === 0 ? 'completed' : 'processing',
                'channel' => 'campaign.'.$user->id,
                'should_subscribe_reverb' => $pending > 0,
            ],
            'status' => 200,
        ]);
    }
}

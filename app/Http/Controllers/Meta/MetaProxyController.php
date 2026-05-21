<?php

namespace App\Http\Controllers\Meta;

use App\Exceptions\Billing\InsufficientCreditsException;
use App\Exceptions\Billing\MessageAlreadyBilledException;
use App\Exceptions\Billing\PricingNotConfiguredException;
use App\Http\Controllers\Controller;
use App\Services\Messaging\OutboundMessageSendService;
use App\Services\Meta\MetaGraphClient;
use App\Traits\ResolvesMetaCredentials;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MetaProxyController extends Controller
{
    use ResolvesMetaCredentials;

    private const PHONE_NUMBERS_CACHE_TTL_MINUTES = 15;

    public function __construct(
        private readonly MetaGraphClient $graph,
    ) {}

    // ─── Phase 1: Messages ──────────────────────────────────────────────────────

    /**
     * POST /api/messaging/send
     */
    public function send(Request $request, OutboundMessageSendService $sendService): JsonResponse
    {
        $validated = $request->validate([
            'to' => 'required|string',
            'billing_category' => ['nullable', 'string', Rule::in(['marketing', 'utility', 'authentication', 'service'])],
        ]);

        /** @var \App\Models\User $user */
        $user = auth()->user();
        $creds = $this->resolveCredentials();

        $payload = $request->all();

        try {
            $result = $sendService->sendAndBill(
                $user,
                $payload,
                $creds['phone_id'],
                $creds['token'],
                $creds['waba_id'],
                $validated['billing_category'] ?? null,
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (InsufficientCreditsException $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
                'code' => 'INSUFFICIENT_CREDITS',
                'current_balance' => $e->currentBalance,
                'required' => $e->required,
            ], 422);
        } catch (PricingNotConfiguredException $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
                'code' => 'PRICING_NOT_CONFIGURED',
            ], 422);
        } catch (MessageAlreadyBilledException $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
                'code' => 'ALREADY_BILLED',
                'wamid' => $e->wamid,
                'out_report_id' => $e->outReportId,
            ], 409);
        }

        $status = $result['ok'] ? 200 : ($result['http'] ?? 500);

        if ($result['ok']) {
            return response()->json([
                'ok' => true,
                'wamid' => $result['wamid'],
                'out_report_id' => $result['out_report_id'],
                'deducted' => $result['deducted'],
                'balance_after' => $result['balance_after'],
            ], $status);
        }

        return response()->json([
            'ok' => false,
            'error' => $result['error'],
            'code' => $result['code'],
            'wamid' => $result['wamid'],
        ], $status);
    }

    // ─── Phase 1: Templates ─────────────────────────────────────────────────────

    public function getTemplates(): JsonResponse
    {
        $creds = $this->resolveCredentials();

        $url = $this->waUrl('WA_API_TEMPLATES', [
            '{{whatsapp_business_account_id}}' => $creds['waba_id'],
            '{{wa_token}}'                     => $creds['token'],
        ]);

        return $this->jsonFromGraph('GET', $url, fn () => $this->graph->get($url, $creds['token']));
    }

    public function createTemplate(Request $request): JsonResponse
    {
        $creds = $this->resolveCredentials();

        $url = $this->waUrl('WA_API_TEMPLATE_SUBMIT', [
            '{{whatsapp_business_account_id}}' => $creds['waba_id'],
        ]);

        return $this->jsonFromGraph('POST', $url, fn () => $this->graph->post($url, $creds['token'], $request->all()));
    }

    public function deleteTemplate(Request $request, string $name): JsonResponse
    {
        $creds = $this->resolveCredentials();

        $url = $this->waUrl('WA_API_TEMPLATE_DELETE', [
            '{{whatsapp_business_account_id}}' => $creds['waba_id'],
            '{{name}}'                         => urlencode($name),
            '{{hsm_id}}'                       => (string) $request->query('hsm_id', ''),
        ]);

        return $this->jsonFromGraph('DELETE', $url, fn () => $this->graph->delete($url, $creds['token']));
    }

    // ─── Phase 1: OAuth Connect ─────────────────────────────────────────────────

    public function connect(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string']);

        $base = rtrim((string) env('META_API_BASE', 'https://graph.facebook.com/v25.0'), '/');

        $result = $this->graph->getWithQuery("{$base}/oauth/access_token", [
            'client_id'     => (string) env('META_APP_ID'),
            'client_secret' => (string) env('META_APP_SECRET'),
            'code'          => $request->input('code'),
        ]);

        $data = $result['body'];

        return response()->json([
            'ok'           => isset($data['access_token']),
            'access_token' => $data['access_token'] ?? null,
        ], $result['status']);
    }

    // ─── Phase 2: Media ─────────────────────────────────────────────────────────

    public function uploadMedia(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file']);

        $creds = $this->resolveCredentials();

        $url = $this->waUrl('WA_API_MEDIA', [
            '{{whatsapp_phone_id}}' => $creds['phone_id'],
        ]);

        $file = $request->file('file');

        return $this->jsonFromGraph('POST', $url, fn () => $this->graph->postMultipart(
            $url,
            $creds['token'],
            $file->get(),
            $file->getClientOriginalName(),
            (string) $request->input('type', $file->getMimeType())
        ));
    }

    public function getMedia(string $mediaId): JsonResponse
    {
        $creds = $this->resolveCredentials();

        $url = $this->waUrl('WA_API_MEDIA_DOWNLOAD', [
            '{{media_id}}'          => $mediaId,
            '{{whatsapp_phone_id}}' => $creds['phone_id'],
        ]);

        return $this->jsonFromGraph('GET', $url, fn () => $this->graph->get($url, $creds['token']));
    }

    // ─── Phase 2: Flows ─────────────────────────────────────────────────────────

    public function getFlows(): JsonResponse
    {
        $creds = $this->resolveCredentials();

        $url = $this->waUrl('WA_API_FLOWS', [
            '{{whatsapp_business_account_id}}' => $creds['waba_id'],
        ]);

        return $this->jsonFromGraph('GET', $url, fn () => $this->graph->get($url, $creds['token']));
    }

    public function createFlow(Request $request): JsonResponse
    {
        $creds = $this->resolveCredentials();

        $url = $this->waUrl('WA_API_FLOWS', [
            '{{whatsapp_business_account_id}}' => $creds['waba_id'],
        ]);

        return $this->jsonFromGraph('POST', $url, fn () => $this->graph->post($url, $creds['token'], $request->all()));
    }

    public function publishFlow(string $flowId): JsonResponse
    {
        $creds = $this->resolveCredentials();

        $url = $this->waUrl('WA_API_FLOW_PUBLISH', [
            '{{flow_id}}' => $flowId,
        ]);

        return $this->jsonFromGraph('POST', $url, fn () => $this->graph->post($url, $creds['token'], ['status' => 'PUBLISHED']));
    }

    public function deleteFlow(string $flowId): JsonResponse
    {
        $creds = $this->resolveCredentials();

        $url = $this->waUrl('WA_API_FLOW_DELETE', [
            '{{flow_id}}' => $flowId,
        ]);

        return $this->jsonFromGraph('DELETE', $url, fn () => $this->graph->delete($url, $creds['token']));
    }

    // ─── Phase 3: Account ───────────────────────────────────────────────────────

    public function phoneNumbers(): JsonResponse
    {
        $creds = $this->resolveCredentials();

        $cacheKey = 'meta:phone_numbers:'.auth()->id().':'.$creds['waba_id'];

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return response()->json($cached['body'], $cached['status']);
        }

        $url = $this->waUrl('WA_API_PHONE_NUMBERS', [
            '{{whatsapp_business_account_id}}' => $creds['waba_id'],
        ]);

        $result = $this->graph->get($url, $creds['token']);
        $this->logMetaCall('GET', $url, $result['status']);

        $payload = [
            'status' => $result['status'],
            'body'   => $this->sanitizeMetaResponse($result['body']),
        ];

        if ($result['status'] >= 200 && $result['status'] < 300) {
            Cache::put($cacheKey, $payload, now()->addMinutes(self::PHONE_NUMBERS_CACHE_TTL_MINUTES));
        }

        return response()->json($payload['body'], $payload['status']);
    }

    /**
     * @param  callable(): array{status: int, body: array<string, mixed>}  $request
     */
    private function jsonFromGraph(string $method, string $url, callable $request): JsonResponse
    {
        $result = $request();

        $this->logMetaCall($method, $url, $result['status']);

        return response()->json(
            $this->sanitizeMetaResponse($result['body']),
            $result['status']
        );
    }
}

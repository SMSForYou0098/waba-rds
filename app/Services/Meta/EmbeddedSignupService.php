<?php

namespace App\Services\Meta;

use App\Models\Settings\UserConfig;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EmbeddedSignupService
{
    public const STATUS_TOKEN_EXCHANGED = 'token_exchanged';

    public const STATUS_WABA_RESOLVED = 'waba_resolved';

    public const STATUS_PHONE_RESOLVED = 'phone_resolved';

    public const STATUS_PHONE_REGISTERED = 'phone_registered';

    public const STATUS_COMPLETED = 'completed';

    private ?string $lastStatus = null;

    public function __construct(
        private readonly MetaGraphClient $graph,
    ) {}

    /**
     * Run embedded signup steps 0–4 strictly in order: call → validate → persist → next.
     *
     * @return array<string, mixed>
     */
    public function complete(User $user, string $code): array
    {
        $base = rtrim((string) env('META_API_BASE', 'https://graph.facebook.com/v25.0'), '/');

        // ── Step 0: OAuth code exchange ─────────────────────────────────────
        $result = $this->graph->getWithQuery("{$base}/oauth/access_token", [
            'client_id'     => (string) env('META_APP_ID'),
            'client_secret' => (string) env('META_APP_SECRET'),
            'code'          => $code,
        ]);

        $accessToken = $result['body']['access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            return $this->fail(0, 'OAuth Token Exchange', $result);
        }

        $this->persistStep($user, [
            'meta_access_token' => $accessToken,
            'app_id'            => (string) env('META_APP_ID'),
        ], null, self::STATUS_TOKEN_EXCHANGED);

        // ── Step 1: Debug token → WABA ID ───────────────────────────────────
        // input_token = onboarding user token; Bearer = Admin token (Meta requires app/admin).
        $debugBearer = $this->resolveAdminDebugBearer();

        if ($debugBearer === null) {
            return $this->fail(1, 'Get WABA ID', [
                'status' => 500,
                'body'   => ['error' => ['message' => 'Admin Meta access token is not configured in userconfigs']],
            ], 'Admin Meta access token is not configured');
        }

        $debugUrl = "{$base}/debug_token?".http_build_query(['input_token' => $accessToken]);
        $result = $this->graph->get($debugUrl, $debugBearer);

        $wabaId = $this->extractWabaId($result['body']);

        if ($wabaId === null) {
            return $this->fail(1, 'Get WABA ID', $result, 'No WhatsApp Business Account found in token scopes');
        }

        $this->persistStep($user, [
            'whatsapp_business_account_id' => $wabaId,
            'business_account_id'        => null,
        ], null, self::STATUS_WABA_RESOLVED);

        // ── Step 2: Phone numbers ─────────────────────────────────────────────
        $result = $this->graph->get("{$base}/{$wabaId}/phone_numbers", $accessToken);

        $phone = $this->extractLatestPhone($result['body']);

        if ($phone === null) {
            return $this->fail(2, 'Get Phone Numbers', $result, 'No phone numbers found for this WABA');
        }

        $this->persistStep($user, [
            'whatsapp_phone_id' => $phone['id'],
        ], $phone['number'], self::STATUS_PHONE_RESOLVED);

        // ── Step 3: Register phone ────────────────────────────────────────────
        $pin = (string) env('WHATSAPP_REGISTER_PIN', '');

        if ($pin === '') {
            return $this->fail(3, 'Register Phone', [
                'status' => 500,
                'body'   => ['error' => ['message' => 'WHATSAPP_REGISTER_PIN is not configured']],
            ], 'WHATSAPP_REGISTER_PIN is not configured');
        }

        $result = $this->graph->post("{$base}/{$phone['id']}/register", $accessToken, [
            'messaging_product' => 'whatsapp',
            'pin'               => $pin,
        ]);

        if (! $this->isMetaSuccess($result)) {
            return $this->fail(3, 'Register Phone', $result);
        }

        $this->persistStep($user, [], null, self::STATUS_PHONE_REGISTERED);

        // ── Step 4: Subscribe app to WABA webhooks ────────────────────────────
        $result = $this->graph->post("{$base}/{$wabaId}/subscribed_apps", $accessToken, []);

        if (! $this->isMetaSuccess($result)) {
            return $this->fail(4, 'Subscribe Webhook', $result);
        }

        $this->persistStep($user, [], null, self::STATUS_COMPLETED);

        return [
            'ok'                => true,
            'step_completed'    => 5,
            'onboarding_status' => self::STATUS_COMPLETED,
            'waba_id'           => $wabaId,
            'phone_id'          => $phone['id'],
            'phone_number'      => $phone['number'],
            'http_status'       => 200,
        ];
    }

    /**
     * Token for debug_token Authorization — Admin userconfig, not the onboarding user token.
     */
    private function resolveAdminDebugBearer(): ?string
    {
        $admin = User::role('Admin')
            ->whereHas('userConfig', function ($query) {
                $query->whereNotNull('meta_access_token')
                    ->where('meta_access_token', '!=', '');
            })
            ->with('userConfig')
            ->first();

        $token = $admin?->userConfig?->meta_access_token;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * @param  array<string, mixed>  $configAttrs
     */
    private function persistStep(User $user, array $configAttrs, ?string $phoneNumber, string $status): void
    {
        DB::transaction(function () use ($user, $configAttrs, $phoneNumber, $status) {
            UserConfig::updateOrCreate(
                ['user_id' => $user->id],
                array_merge($configAttrs, ['onboarding_status' => $status])
            );

            if ($phoneNumber !== null && $phoneNumber !== '') {
                $user->whatsapp_number = (int) $phoneNumber;
                $user->save();
            }
        });

        $this->lastStatus = $status;
    }

    /**
     * @param  array{status: int, body: array<string, mixed>}  $result
     * @return array<string, mixed>
     */
    private function fail(int $step, string $label, array $result, ?string $overrideMessage = null): array
    {
        $message = $overrideMessage ?? $this->metaErrorMessage($result);

        return [
            'ok'                => false,
            'failed_at'         => $step,
            'step_label'        => $label,
            'onboarding_status' => $this->lastStatus,
            'error'             => $message,
            'http_status'       => $this->httpStatusForMetaResult($result),
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function extractWabaId(array $body): ?string
    {
        $scopes = $body['data']['granular_scopes'] ?? [];

        if (! is_array($scopes)) {
            return null;
        }

        foreach ($scopes as $scope) {
            if (! is_array($scope)) {
                continue;
            }

            if (($scope['scope'] ?? '') !== 'whatsapp_business_management') {
                continue;
            }

            $targetIds = $scope['target_ids'] ?? [];

            if (is_array($targetIds) && isset($targetIds[0]) && is_string($targetIds[0]) && $targetIds[0] !== '') {
                return $targetIds[0];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{id: string, number: string}|null
     */
    private function extractLatestPhone(array $body): ?array
    {
        $numbers = $body['data'] ?? [];

        if (! is_array($numbers) || $numbers === []) {
            return null;
        }

        $latest = collect($numbers)
            ->filter(fn ($item) => is_array($item) && isset($item['id']))
            ->sortByDesc(function ($item) {
                return strtotime((string) ($item['last_onboarded_time'] ?? '1970-01-01'));
            })
            ->first();

        if (! is_array($latest)) {
            return null;
        }

        $id = (string) ($latest['id'] ?? '');
        $display = (string) ($latest['display_phone_number'] ?? '');
        $digits = preg_replace('/[^0-9]/', '', $display) ?? '';

        if ($id === '' || $digits === '') {
            return null;
        }

        return ['id' => $id, 'number' => $digits];
    }

    /**
     * @param  array{status: int, body: array<string, mixed>}  $result
     */
    private function isMetaSuccess(array $result): bool
    {
        if (! $this->isHttpSuccess($result['status'])) {
            return false;
        }

        if (array_key_exists('success', $result['body'])) {
            return $result['body']['success'] === true;
        }

        return ! isset($result['body']['error']);
    }

    private function isHttpSuccess(int $status): bool
    {
        return $status >= 200 && $status < 300;
    }

    /**
     * @param  array{status: int, body: array<string, mixed>}  $result
     */
    private function metaErrorMessage(array $result): string
    {
        $message = $result['body']['error']['message'] ?? null;

        if (is_string($message) && $message !== '') {
            return $message;
        }

        return 'Request to Meta API failed';
    }

    /**
     * @param  array{status: int, body: array<string, mixed>}  $result
     */
    private function httpStatusForMetaResult(array $result): int
    {
        $status = $result['status'];

        if ($status >= 400 && $status < 600) {
            return 422;
        }

        if ($status >= 200 && $status < 300) {
            return 422;
        }

        return 502;
    }
}

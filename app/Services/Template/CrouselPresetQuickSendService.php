<?php

namespace App\Services\Template;

use App\Exceptions\Billing\InsufficientCreditsException;
use App\Exceptions\Billing\MessageAlreadyBilledException;
use App\Exceptions\Billing\PricingNotConfiguredException;
use App\Models\Template\CrouselPreset;
use App\Services\Messaging\OutboundMessageSendService;
use App\Traits\ResolvesApiKeyTenant;
use Illuminate\Http\Request;

class CrouselPresetQuickSendService
{
    use ResolvesApiKeyTenant;

    private const ALLOWED_PARAMS = ['to', 'apikey', 'preset'];

    public function __construct(
        private readonly OutboundMessageSendService $outboundSendService,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     http_status: int,
     *     status?: bool,
     *     message?: string,
     *     message_id?: string,
     *     error?: string,
     *     error_code?: string,
     *     invalid_params?: array<int, string>,
     *     success?: mixed
     * }
     */
    public function execute(Request $request): array
    {
        $params = $request->only(self::ALLOWED_PARAMS);

        if (count($params) !== 3) {
            return [
                'ok' => false,
                'http_status' => 400,
                'status' => false,
                'error' => 'Invalid parameter(s)',
                'invalid_params' => array_values(array_diff(array_keys($request->query()), self::ALLOWED_PARAMS)),
            ];
        }

        $to = (string) $params['to'];
        $apikey = (string) $params['apikey'];
        $presetName = (string) $params['preset'];

        if (! is_numeric($to) || strlen($to) < 10) {
            return [
                'ok' => false,
                'http_status' => 401,
                'status' => false,
                'error' => 'Invalid Mobile number',
                'error_code' => 'SF2',
            ];
        }

        $apiKey = $this->resolveActiveApiKey($apikey);
        $this->assertApiKeyIpAllowed($apiKey, $request->ip());

        $user = $this->tenantUser($apiKey);
        $this->assertWhatsAppConfigured($user);

        $config = $user->userConfig;
        $phoneId = (string) $config->whatsapp_phone_id;
        $waToken = (string) $config->meta_access_token;
        $wabaId = $config->whatsapp_business_account_id
            ? (string) $config->whatsapp_business_account_id
            : null;

        $presetObject = CrouselPreset::query()
            ->where('user_id', $user->id)
            ->where('name', $presetName)
            ->value('object');

        if (! $presetObject) {
            return [
                'ok' => false,
                'http_status' => 404,
                'status' => false,
                'error' => 'Preset not found',
            ];
        }

        $object = str_replace(':number:', $to, (string) $presetObject);
        $payload = json_decode($object, true);

        if (! is_array($payload)) {
            return [
                'ok' => false,
                'http_status' => 400,
                'status' => false,
                'error' => 'Invalid preset payload',
                'error_code' => 'PRESET_INVALID',
            ];
        }

        if (empty($payload['to'])) {
            $payload['to'] = $to;
        }

        $billingCategory = strtolower((string) ($payload['type'] ?? '')) === 'template'
            ? null
            : 'marketing';

        try {
            $result = $this->outboundSendService->sendAndBill(
                $user,
                $payload,
                $phoneId,
                $waToken,
                $wabaId,
                $billingCategory,
            );
        } catch (InsufficientCreditsException) {
            return [
                'ok' => false,
                'http_status' => 401,
                'status' => false,
                'error' => 'Insufficient Credits to send a message. Please recharge your account to use our api smoothly. Thank You',
                'error_code' => 'SF1',
            ];
        } catch (PricingNotConfiguredException $e) {
            return [
                'ok' => false,
                'http_status' => 422,
                'status' => false,
                'error' => $e->getMessage(),
                'error_code' => 'SF8',
            ];
        } catch (MessageAlreadyBilledException $e) {
            return [
                'ok' => true,
                'http_status' => 200,
                'status' => true,
                'message_id' => encrypt($e->wamid),
                'message' => 'Message submitted successfully',
            ];
        }

        if ($result['ok'] && $result['wamid'] !== null) {
            return [
                'ok' => true,
                'http_status' => 200,
                'status' => true,
                'message_id' => encrypt($result['wamid']),
                'message' => 'Message submitted successfully',
            ];
        }

        return [
            'ok' => false,
            'http_status' => $result['http'] ?? 500,
            'status' => false,
            'error' => $result['error'] ?? 'Something Went Wrong',
            'error_code' => $result['code'] ?? 'MESSAGE_SENDING_ERROR',
        ];
    }
}

<?php

namespace App\Http\Controllers\Messaging;

use App\Exceptions\Messaging\LegacyApiValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messaging\SendWhatsAppApiMessageRequest;
use App\Http\Requests\Messaging\SendWhatsAppMediaMessageRequest;
use App\Http\Resources\Messaging\LegacyApiBulkSendResource;
use App\Http\Resources\Messaging\LegacyApiErrorResource;
use App\Services\Messaging\LegacyApi\LegacyApiBulkSendService;
use App\Services\Messaging\LegacyApi\LegacyApiMediaMessageService;
use App\Services\Messaging\LegacyApi\LegacyApiSendPreparationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class WhatsAppMessageRequest extends Controller
{
    public function __construct(
        private readonly LegacyApiSendPreparationService $preparationService,
        private readonly LegacyApiBulkSendService $bulkSendService,
        private readonly LegacyApiMediaMessageService $mediaMessageService,
    ) {}

    public function sendMessages(SendWhatsAppApiMessageRequest $request): JsonResponse
    {
        try {
            $context = $this->preparationService->prepare($request);
            $result = $this->bulkSendService->sendAll($context);

            return (new LegacyApiBulkSendResource($result))->response();
        } catch (LegacyApiValidationException $e) {
            return $this->legacyErrorResponse($e);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }
    }

    public function sendMediaMessage(SendWhatsAppMediaMessageRequest $request): JsonResponse
    {
        try {
            $result = $this->mediaMessageService->send($request);

            if ($result['ok']) {
                return response()->json([
                    'status' => $result['status'] ?? true,
                    'message' => $result['message'] ?? 'Message submitted successfully',
                ], $result['http_status']);
            }

            return (new LegacyApiErrorResource([
                'error' => $result['error'] ?? 'API request failed',
                'error_code' => $result['error_code'] ?? 'MESSAGE_SENDING_ERROR',
            ]))->response()->setStatusCode($result['http_status']);
        } catch (LegacyApiValidationException $e) {
            return $this->legacyErrorResponse($e);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }
    }

    private function validationErrorResponse(ValidationException $e): JsonResponse
    {
        if (isset($e->errors()['params'])) {
            return response()->json([
                'status' => false,
                'error' => 'Invalid parameter(s)',
                'invalid_params' => $e->errors()['params'][0] ?? null,
            ], 400);
        }

        $first = collect($e->errors())->flatten()->first();

        return (new LegacyApiErrorResource([
            'error' => $first ?? 'Validation failed',
            'error_code' => 'VAL000',
        ]))->response()->setStatusCode(422);
    }

    private function legacyErrorResponse(LegacyApiValidationException $e): JsonResponse
    {
        $payload = [
            'error' => $e->getMessage(),
            'error_code' => $e->errorCode,
        ];

        if ($e->errorCode === 'VAL005' && str_contains($e->getMessage(), '100MB')) {
            $payload['message'] = $e->getMessage();
            unset($payload['error_code']);

            return response()->json(['status' => false] + $payload, $e->httpStatus);
        }

        return (new LegacyApiErrorResource($payload))
            ->response()
            ->setStatusCode($e->httpStatus);
    }
}

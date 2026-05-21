<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Services\User\UserBrandingService;
use App\Services\User\UserCreditValidationService;
use App\Services\User\UserListingService;
use App\Services\User\UserManagementService;
use App\Services\User\UserMobileNumberQueryService;
use App\Services\User\UserOtpService;
use App\Services\User\UserReportingService;
use App\Services\User\UserSecurityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        private readonly UserListingService $listingService,
        private readonly UserManagementService $managementService,
        private readonly UserSecurityService $securityService,
        private readonly UserBrandingService $brandingService,
        private readonly UserOtpService $otpService,
        private readonly UserReportingService $reportingService,
        private readonly UserCreditValidationService $creditValidationService,
        private readonly UserMobileNumberQueryService $mobileNumberQueryService,
    ) {}

    public function index(): JsonResponse
    {
        $data = $this->listingService->listForAuthenticatedUser();

        return response()->json([
            'user' => $data['users'],
            'roles' => $data['roles'],
        ]);
    }

    public function getUsersWithConfig(): JsonResponse
    {
        return response()->json([
            'status' => 'true',
            'data' => $this->listingService->listWithConfig(),
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        $result = $this->managementService->create($request);
        $status = $result['http_status'];
        unset($result['http_status']);

        return response()->json($result, $status);
    }

    public function verifyEmail(string $id): JsonResponse
    {
        $result = $this->managementService->verifyEmail($id);
        $status = $result['http_status'];
        unset($result['http_status']);

        return response()->json($result, $status);
    }

    public function CheckValidUser(string $id): JsonResponse
    {
        return response()->json($this->creditValidationService->checkValidUser($id));
    }

    public function UpdateUserSecurity(Request $request): JsonResponse
    {
        $result = $this->securityService->updateSecurity($request);
        $status = $result['http_status'];
        unset($result['http_status']);

        return response()->json($result, $status);
    }

    public function checkPassword(Request $request): JsonResponse
    {
        $result = $this->securityService->checkPassword($request);
        $status = $result['http_status'];
        unset($result['http_status']);

        return response()->json($result, $status);
    }

    public function CreditLimit(Request $request): JsonResponse
    {
        $result = $this->securityService->updateCreditLimit($request);
        $status = $result['http_status'];
        unset($result['http_status']);

        return response()->json($result, $status);
    }

    public function edit(string $id): JsonResponse
    {
        return response()->json($this->listingService->editPayload($id));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $result = $this->managementService->update($request, $id);
        $status = $result['http_status'];
        unset($result['http_status']);

        return response()->json($result, $status);
    }

    public function updateAlerts(Request $request, string $id): JsonResponse
    {
        $result = $this->managementService->updateAlerts($request, $id);
        $status = $result['http_status'];
        unset($result['http_status']);

        return response()->json($result, $status);
    }

    public function lowBalanceUser(string $id): JsonResponse
    {
        return response()->json(['user' => $this->reportingService->lowBalanceUsers()]);
    }

    public function destroyLogs(): JsonResponse
    {
        $this->reportingService->clearLogs();

        return response()->json(['status' => 'true'], 200);
    }

    public function logs(): JsonResponse
    {
        return response()->json([
            'status' => 'true',
            'logs' => $this->reportingService->todayLogs(),
        ], 200);
    }

    public function destroy(string $id): JsonResponse
    {
        $result = $this->managementService->destroy($id);
        $status = $result['http_status'];
        unset($result['http_status']);

        return response()->json($result, $status);
    }

    public function sendOtp(Request $request): JsonResponse
    {
        $result = $this->otpService->send($request);
        $status = $result['http_status'];
        unset($result['http_status']);

        return response()->json($result, $status);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $result = $this->otpService->verify($request);
        $status = $result['http_status'];
        unset($result['http_status']);

        return response()->json($result, $status);
    }

    public function getBrandingConfiguration(Request $request): JsonResponse
    {
        try {
            $request->validate(['host_url' => 'required|string']);
            $result = $this->brandingService->getByHostUrl((string) $request->host_url);
            $status = $result['http_status'];
            unset($result['http_status']);

            return response()->json($result, $status);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'false',
                'message' => 'An error occurred while retrieving branding configuration: '.$e->getMessage(),
            ], 500);
        }
    }

    public function getMobileNumbersOptimized(Request $request): JsonResponse
    {
        try {
            $option = $request->get('option');

            if (! $option) {
                return response()->json(['error' => 'Option parameter is required'], 400);
            }

            $result = $this->mobileNumberQueryService->fetchByOption((string) $option);

            if (! $result['success']) {
                return response()->json(['error' => $result['error'] ?? 'Request failed'], 400);
            }

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'An error occurred while fetching mobile numbers: '.$e->getMessage(),
            ], 500);
        }
    }

    public function getUsersBalanceAndCampaignData(): JsonResponse
    {
        $result = $this->reportingService->balanceAndCampaignData();
        $status = $result['http_status'];
        unset($result['http_status']);

        return response()->json($result, $status);
    }
}

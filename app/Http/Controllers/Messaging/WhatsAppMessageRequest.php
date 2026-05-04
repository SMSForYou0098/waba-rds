<?php

namespace App\Http\Controllers\Messaging;

use App\Http\Controllers\Controller;
use App\Models\Auth\ApiKey;
use App\Models\Report\OutReport;
use App\Models\Chat\ChatHistory;
use App\Models\Report\ApiTemplateReport;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class WhatsAppMessageRequest extends Controller
{
    protected $client;

    public function __construct()
    {
        $this->client = new Client();
    }

public function sendMessages(Request $request)
{
    $expectedParams = ['to', 'message', 'apikey', 'type', 'tname', 'media_url', 'media_id', 'values', 'button_value', 'file_name', 'file', 'report_id'];

    // Get all parameters based on request type
    $params = $request->isMethod('get') ? $request->query() : $request->all();

    // Check for unexpected parameters
    $unexpectedParams = array_diff(array_keys($params), $expectedParams);
    if (!empty($unexpectedParams)) {
        return response()->json([
            'status' => false,
            'error' => 'Invalid parameter(s)',
            'invalid_params' => reset($unexpectedParams)
        ], 400);
    }

    // Retrieve required data
    $toNumbers = explode(',', $params['to'] ?? '');
    $apikey = $params['apikey'] ?? null;
    $message = $params['message'] ?? null;
    $reqType = $params['type'] ?? null;
    $templateName = $params['tname'] ?? null;
    $mediaLink = $params['media_url'] ?? null;
    $mediaID = $params['media_id'] ?? null;
    $dynamicValues = $params['values'] ?? null;
    $fileName = $params['file_name'] ?? null;
    $reportId = $params['report_id'] ?? null;
    $urlButtonValue = $params['button_value'] ?? null;
    $mediaFile = $request->file('file');
    $ip = $request->ip();

    // ============= COMPREHENSIVE VALIDATION =============
    $validationResult = $this->validateMessageRequest(
        $apikey,
        $toNumbers,
        $reqType,
        $message,
        $templateName,
        $mediaLink,
        $mediaID,
        $dynamicValues,
        $urlButtonValue,
        $mediaFile,
        $fileName,
        $ip
    );

    // If validation failed, return error response
    if (!$validationResult['valid']) {
        return response()->json([
            'status' => false,
            'error' => $validationResult['error'],
            'error_code' => $validationResult['error_code'] ?? null
        ], $validationResult['status_code']);
    }

    // Extract validated data
    $validatedData = $validationResult['data'];

    // ============= PROCESS PHONE NUMBERS =============
    $successCount = 0;
    $failureCount = 0;
    $failedNumbers = [];

    foreach ($toNumbers as $to) {
        $to = trim($to);

        // Only validate phone number format here (number-specific validation)
        if (empty($to) || !is_numeric($to) || !in_array(strlen($to), [10, 12])) {
            $failureCount++;
            $failedNumbers[] = [
                'number' => $to,
                'error' => 'Invalid phone number format',
                'error_code' => 'SF2',
                'error_type' => 'validation_error'
            ];
            continue;
        }

        // Send message with validated data
        $response = $this->sendSingleMessage(
            $to,
            $validatedData['user'],
            $message,
            $reqType,
            $templateName,
            $mediaLink,
                $validatedData['mediaID'],
                $validatedData['valuesArray'],
                $validatedData['fileName'],
            $validatedData['ButtonValue'],
            $validatedData['templateData'],
            $validatedData['templateLanguage'],
            $validatedData['messagesApi'],
            $validatedData['waToken'],
            $reportId
        );
		//return $response;
        if ($response->getStatusCode() === 200) {
            $successCount++;
        } else {
            $failureCount++;
            $responseData = json_decode($response->getContent(), true);
            // Enhanced error handling with Meta API error details
            $errorInfo = [
                'number' => $to,
                'error' => $responseData['error'] ?? 'Unknown error',
                'error_code' => $responseData['error_code'] ?? 'UNKNOWN',
                'error_type' => $responseData['error_type'] ?? 'api_error'
            ];

            // Add Meta API specific error details if available
            if (isset($responseData['meta_error'])) {
                $errorInfo['meta_error'] = $responseData['meta_error'];
            }

            if (isset($responseData['meta_error_code'])) {
                $errorInfo['meta_error_code'] = $responseData['meta_error_code'];
            }

            if (isset($responseData['meta_error_subcode'])) {
                $errorInfo['meta_error_subcode'] = $responseData['meta_error_subcode'];
            }

            $failedNumbers[] = $errorInfo;
        }
    }

    return response()->json([
        'status' => true,
        'success_count' => $successCount,
        'failure_count' => $failureCount,
        'error_summary' => $failedNumbers,
        //'error_summary' => $this->generateErrorSummary($failedNumbers),
        'message' => 'Message sending process completed'
    ]);
}

/**
 * Comprehensive validation method for message requests
 *
 * @param string|null $apikey
 * @param array $toNumbers
 * @param string|null $reqType
 * @param string|null $message
 * @param string|null $templateName
 * @param string|null $mediaLink
 * @param string|null $mediaID
 * @param string|null $dynamicValues
 * @param string|null $urlButtonValue
 * @param \Illuminate\Http\UploadedFile|null $mediaFile
 * @param string $ip
 * @return array
 */
private function validateMessageRequest($apikey, $toNumbers, $reqType, $message, $templateName, $mediaLink, $mediaID, $dynamicValues, $urlButtonValue, $mediaFile,$fileName, $ip)
{
    // 1. VALIDATE BASIC REQUIRED FIELDS
    if (empty($apikey)) {
        return [
            'valid' => false,
            'error' => 'API key is required',
            'error_code' => 'VAL001',
            'status_code' => 400
        ];
    }

    if (empty($toNumbers) || (count($toNumbers) === 1 && empty(trim($toNumbers[0])))) {
        return [
            'valid' => false,
            'error' => 'At least one phone number is required',
            'error_code' => 'VAL002',
            'status_code' => 400
        ];
    }

    // 2. VALIDATE REQUEST TYPE
    $allowedTypes = ['C', 'T', 'M']; // Define your allowed types
    if (!in_array($reqType, $allowedTypes)) {
        return [
            'valid' => false,
            'error' => 'Invalid request type. Allowed types: ' . implode(', ', $allowedTypes),
            'error_code' => 'VAL003',
            'status_code' => 400
        ];
    }

    // 3. VALIDATE API KEY AND GET USER DATA
    $user = ApiKey::where('status', 'true')->where('key', $apikey)->with('user.userConfig')->first();
    if (!$user) {
        return [
            'valid' => false,
            'error' => 'Invalid API key',
            'error_code' => 'SF0',
            'status_code' => 401
        ];
    }

    // 4. VALIDATE CREDITS
      $userBalance = $user->user->latestBalance;
      $totalBalance = $userBalance ? $userBalance->total_credits : 0;
        // 4. VALIDATE CREDITS
      if ($totalBalance < 1) {
            return [
                'valid' => false,
                'error' => 'Insufficient Credits to send a message. Please recharge your account to use our API smoothly. Thank You',
                'error_code' => 'SF1',
                'status_code' => 401
            ];
      }

    // 5. VALIDATE IP AUTHENTICATION
    if ($user->ip_auth == '1') {
        $userIPs = json_decode($user);
        $ipAddress = explode(',', $userIPs->ip_addresses);
        $ipAddress = array_filter($ipAddress, function ($value) {
            return $value !== '';
        });

        if (!in_array($ip, $ipAddress)) {
            return [
                'valid' => false,
                'error' => 'IP Authentication failed',
                'error_code' => 'SF7',
                'status_code' => 401
            ];
        }
    }

    // 6. VALIDATE MESSAGE CONTENT FOR CUSTOM MESSAGES
    if ($reqType == 'C' && empty($message)) {
        return [
            'valid' => false,
            'error' => 'Message content is required for custom messages',
            'error_code' => 'SF3',
            'status_code' => 400
        ];
    }

    // 7. VALIDATE TEMPLATE NAME FOR TEMPLATE MESSAGES
    if ($reqType !== 'C' && empty($templateName)) {
        return [
            'valid' => false,
            'error' => 'Template name is required for template messages',
            'error_code' => 'VAL004',
            'status_code' => 400
        ];
    }

    // 8. PREPARE CONFIGURATION DATA
    $whatapp_phone_id = $user->user->userConfig->whatsapp_phone_id;
    $whatsapp_business_account_id = $user->user->userConfig->whatsapp_business_account_id;
    $waToken = $user->user->userConfig->meta_access_token;
    $messageSendApi = env('WA_API_MESSAGES');
    $messagesApi = str_replace(':whatsapp_phone_id:', $whatapp_phone_id, $messageSendApi);
    $templates = env('WA_API_TEMPLATES');

    // 9. VALIDATE AND PREPARE TEMPLATE DATA (IF NEEDED)
    $templateData = null;
    $templateLanguage = 'en_US';
    $valuesArray = [];
    $ButtonValue = [];

    if ($reqType !== 'C') {
        // Get template data
        $templateData = $this->getTemplateData($reqType, $whatsapp_business_account_id, $templates, $waToken, $templateName);

        if (isset($templateData['error'])) {
            return [
                'valid' => false,
                'error' => $templateData['error'],
                'error_code' => 'SF4',
                'status_code' => 400
            ];
        }

        $templateLanguage = $templateData['Templatelanguage'] ?? 'en_US';

        // Prepare dynamic values and buttons
        if (!empty($dynamicValues)) {
            $valuesArray = explode(',', $dynamicValues);
            $valuesArray = array_filter($valuesArray, function ($value) {
                return $value !== '';
            });
        }

        if (!empty($urlButtonValue)) {
            $ButtonValue = explode(",", $urlButtonValue);
            $ButtonValue = array_filter($ButtonValue, function ($value) {
                return $value !== '';
            });
        }

        // Validate template requirements
        $headerType = $templateData['header']['format'] ?? null;
        $body = $templateData['body']['text'] ?? '';

        // Count dynamic values in template
        $pattern = '/{{\d+}}/';
        preg_match_all($pattern, $body, $matches);
        $numDynamicValues = count($matches[0]);

        // Get button information
        $templateButtons = $templateData['buttons']['buttons'][0] ?? null;
        $buttonType = $templateButtons['type'] ?? null;

        // Validate media requirements
        if ($headerType && $headerType !== 'TEXT' && !$mediaLink && !$mediaID && !$mediaFile) {
            return [
                'valid' => false,
                'error' => 'Media url or file is required for this template',
                'error_code' => 'SF4',
                'status_code' => 400
            ];
        }

        // Validate dynamic values count
        if ($numDynamicValues > 0 && $numDynamicValues !== count($valuesArray)) {
            return [
                'valid' => false,
                'error' => "Number of dynamic values doesn't match template requirements. Expected: {$numDynamicValues}, Provided: " . count($valuesArray),
                'error_code' => 'SF5',
                'status_code' => 400
            ];
        }
      
		// VALIDATE WHEN USER PROVIDES VALUES BUT TEMPLATE DOESN'T NEED ANY
        if ($numDynamicValues === 0 && count($valuesArray) > 0) {
            return [
                'valid' => false,
                'error' => "This template doesn't require any dynamic values, but " . count($valuesArray) . " value(s) were provided.",
                'error_code' => 'SF5',
                'status_code' => 400
            ];
        }
                // Validate button parameters
        if ($buttonType === "URL" && isset($templateButtons['example']) && count($ButtonValue) === 0) {
            return [
                'valid' => false,
                'error' => "Button parameter is required for URL button templates",
                'error_code' => 'SF6',
                'status_code' => 400
            ];
        }
    }

    // 10. HANDLE MEDIA FILE UPLOAD (IF NEEDED)
    if ($mediaFile) {
        try {
            $media = $this->prepareMediaRequest($whatapp_phone_id, $mediaFile, $waToken, $messagesApi, null, $user, 'Custom');
            $fileName = $media['filename'];
            $mediaID = $media['id'];
        } catch (Exception $e) {
            return [
                'valid' => false,
                'error' => 'Media upload failed: ' . $e->getMessage(),
                'error_code' => 'VAL005',
                'status_code' => 400
            ];
        }
    }

    // 11. ALL VALIDATIONS PASSED - RETURN SUCCESS WITH DATA
    return [
        'valid' => true,
        'data' => [
            'user' => $user,
            'templateData' => $templateData,
            'templateLanguage' => $templateLanguage,
            'valuesArray' => $valuesArray,
            'ButtonValue' => $ButtonValue,
            'messagesApi' => $messagesApi,
            'waToken' => $waToken,
            'whatapp_phone_id' => $whatapp_phone_id,
            'whatsapp_business_account_id' => $whatsapp_business_account_id,
            'fileName' => $fileName,      // <-- add this
            'mediaID' => $mediaID
        ]
    ];
}

/**
 * Validate individual phone number format
 *
 * @param string $phoneNumber
 * @return array
 */
private function validatePhoneNumber($phoneNumber)
{
    $phoneNumber = trim($phoneNumber);

    if (empty($phoneNumber)) {
        return [
            'valid' => false,
            'error' => 'Phone number cannot be empty',
            'error_code' => 'SF2'
        ];
    }

    if (!is_numeric($phoneNumber)) {
        return [
            'valid' => false,
            'error' => 'Phone number must contain only digits',
            'error_code' => 'SF2'
        ];
    }

    if (!in_array(strlen($phoneNumber), [10, 12])) {
        return [
            'valid' => false,
            'error' => 'Phone number must be 10 or 12 digits long',
            'error_code' => 'SF2'
        ];
    }

    return [
        'valid' => true,
        'phone_number' => $phoneNumber
    ];
}

// Enhanced method for sending individual messages with detailed error handling
private function sendSingleMessage($to, $user, $message, $reqType, $templateName, $mediaLink, $mediaID, $valuesArray, $fileName, $ButtonValue, $templateData, $templateLanguage, $messagesApi, $waToken, $reportId)
{
    try {
        $data = $this->prepareRequestData(
            $to, $message, $reqType, $templateName, $valuesArray, $mediaLink, 
            $templateData, $ButtonValue, $fileName, $mediaID, $templateLanguage
        );
   	 	//return $data;
        $response = $this->client->post($messagesApi, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $waToken,
            ],
            'body' => json_encode($data),
            'http_errors' => false  // ADD THIS LINE - prevents Guzzle from throwing exceptions on 4xx/5xx
        ]);

        $statusCode = $response->getStatusCode();
        $responseBody = $response->getBody()->getContents();
        $responseData = json_decode($responseBody, true);

        // Success case
        if (isset($responseData['messages'][0]['id'])) {
            $this->MakeOutReport($to, $user->user->whatsapp_number, $responseData['messages'][0]['id'], 
                $user->user->id, $templateName, $mediaID, $mediaLink, $valuesArray, $ButtonValue, $reportId);

            return response()->json([
                'status' => true,
                'message_id' => encrypt($responseData['messages'][0]['id']),
                'message' => 'Message sent successfully'
            ], 200);
        }

        // Handle API errors (400, 401, 403, etc.)
        if (isset($responseData['error'])) {
            $metaError = $responseData['error'];
            
            return response()->json([
                'status' => false,
                'error' => $this->getReadableError($metaError),
                'error_code' => 'MESSAGE_SENDING_ERROR',
                'error_type' => 'sending_error',
                'meta_error' => $metaError['message'] ?? 'Unknown API error',
                'meta_error_code' => $metaError['code'] ?? null,
                'meta_error_subcode' => $metaError['error_subcode'] ?? null,
                'meta_fbtrace_id' => $metaError['fbtrace_id'] ?? null
            ], $statusCode >= 400 ? $statusCode : 400);
        }

        // Handle other HTTP errors without error field
        if ($statusCode >= 400) {
            return response()->json([
                'status' => false,
                'error' => 'Message sending failed',
                'error_code' => 'MESSAGE_SENDING_ERROR', 
                'error_type' => 'sending_error',
                'status_code' => $statusCode,
                'raw_response' => $responseData
            ], $statusCode);
        }

        // Success without message ID
        if ($statusCode >= 200 && $statusCode < 300) {
            return response()->json(['status' => true, 'message' => 'Message sent successfully'], 200);
        }

    } catch (GuzzleException $e) {
        // Only true connection/network errors reach here
        return response()->json([
            'status' => false,
            'error' => 'Connection failed. Please try again.',
            'error_code' => 'CONNECTION_ERROR',
            'error_type' => 'connection_error',
            'details' => $e->getMessage()
        ], 500);
    } catch (Exception $e) {
        return response()->json([
            'status' => false,
            'error' => 'Service temporarily unavailable',
            'error_code' => 'SERVICE_ERROR', 
            'error_type' => 'service_error',
            'details' => $e->getMessage()
        ], 500);
    }
}

/**
 * Convert Meta API error codes to readable messages
 *
 * @param array $metaError
 * @return string
 */
/**
 * Convert Meta API error codes to readable messages
 * 
 * @param array $metaError
 * @return string
 */
private function getReadableError($metaError)
{
    $errorCode = $metaError['code'] ?? null;
    $errorSubcode = $metaError['error_subcode'] ?? null;
    $errorMessage = $metaError['message'] ?? 'Unknown error';

    // Common Meta WhatsApp API error codes
    $readableErrors = [
        // Parameter validation errors
        '100' => $this->getParameter100Error($errorMessage),
        
        // Rate limiting
        '4' => 'Rate limit exceeded. Too many requests sent.',
        '80007' => 'Rate limit exceeded for this phone number.',
        
        // Authentication errors
        '190' => 'Access token is invalid or expired.',
        '102' => 'API session expired or invalid.',
        
        // Phone number errors
        '131056' => 'Phone number is not registered with WhatsApp Business.',
        '131051' => 'Invalid phone number format.',
        '131052' => 'Phone number blocked or restricted.',
        
        // Template errors
        '132000' => 'Template does not exist or is not approved.',
        '132001' => 'Template parameters are invalid.',
        '132005' => 'Template format is incorrect.',
        '132012' => 'Template parameter count mismatch.',
        '132015' => 'Template is paused or rejected.',
        '132016' => 'Template message limit exceeded.',
        
        // Media errors
        '133000' => 'Media upload failed.',
        '133004' => 'Media file size too large.',
        '133005' => 'Media file format not supported.',
        '133006' => 'Media file corrupted or invalid.',
        
        // Business account errors
        '136000' => 'WhatsApp Business Account not found.',
        '136001' => 'Phone number not associated with Business Account.',
        '136002' => 'Business Account temporarily restricted.',
        
        // Message errors
        '131009' => 'Cannot send message to this user.',
        '131021' => 'Recipient phone number not reachable.',
        '131026' => 'Message could not be delivered.',
        '131047' => 'Re-engagement message window expired.',
        '131053' => 'User has blocked the business number.',
        '131059' => 'Invalid pagination cursor while fetching template data. Retry without cursor.',
        '131064' => 'Messaging restricted due to template classification violations. Retry after enforcement period.',
        '132018' => 'Template send request has invalid parameters. Please verify template payload.',
    ];

    // Try to get readable error by error code first
    if ($errorCode && isset($readableErrors[$errorCode])) {
        return $readableErrors[$errorCode];
    }

    // Try by subcode if main code not found
    if ($errorSubcode && isset($readableErrors[$errorSubcode])) {
        return $readableErrors[$errorSubcode];
    }

    // Return simplified message if no mapping found
    return $this->simplifyGenericError($errorMessage);
}

/**
 * Handle specific error code 100 cases
 */
private function getParameter100Error($errorMessage)
{
    // Check for common parameter validation issues
    if (strpos($errorMessage, "image']['link'] is not a valid URI") !== false) {
        return 'Invalid image URL provided. Please check the media link.';
    }
    
    if (strpos($errorMessage, "video']['link'] is not a valid URI") !== false) {
        return 'Invalid video URL provided. Please check the media link.';
    }
    
    if (strpos($errorMessage, "document']['link'] is not a valid URI") !== false) {
        return 'Invalid document URL provided. Please check the media link.';
    }
    
    if (strpos($errorMessage, "audio']['link'] is not a valid URI") !== false) {
        return 'Invalid audio URL provided. Please check the media link.';
    }
    
    if (strpos($errorMessage, 'template') !== false && strpos($errorMessage, 'parameters') !== false) {
        return 'Template parameters are incorrect. Please verify your template data.';
    }
    
    if (strpos($errorMessage, 'recipient') !== false) {
        return 'Invalid recipient phone number format.';
    }
    
    if (strpos($errorMessage, 'message') !== false) {
        return 'Message content is invalid or missing required fields.';
    }

    // Default for error code 100
    return 'Invalid request parameters. Please check your input data.';
}

/**
 * Simplify generic error messages
 */
private function simplifyGenericError($errorMessage)
{
    // Common patterns to simplify
    $patterns = [
        '/\(\#\d+\)\s*/' => '', // Remove error code like (#100)
        '/Param\s+/' => '', // Remove "Param" prefix
        '/template\[\'components\'\]\[\d+\]\[\'parameters\'\]\[\d+\]\[\'/' => '', // Simplify template path
        '/\'\]\[\'/' => ' -> ', // Replace array notation
        '/\'\]/' => '', // Remove trailing array notation
        '/is not a valid URI\./' => 'URL is invalid.',
        '/is required\./' => 'is missing.',
    ];

    $simplified = $errorMessage;
    foreach ($patterns as $pattern => $replacement) {
        $simplified = preg_replace($pattern, $replacement, $simplified);
    }

    return ucfirst(trim($simplified));
}

/**
 * Generate error summary for bulk operations
 *
 * @param array $failedNumbers
 * @return array
 */
private function generateErrorSummary($failedNumbers)
{
    if (empty($failedNumbers)) {
        return [];
    }

    $errorCounts = [];
    $errorTypes = [];
    $metaErrorCodes = [];

    foreach ($failedNumbers as $failed) {
        // Count error codes
        $errorCode = $failed['error_code'] ?? 'UNKNOWN';
        $errorCounts[$errorCode] = ($errorCounts[$errorCode] ?? 0) + 1;

        // Count error types
        $errorType = $failed['error_type'] ?? 'unknown';
        $errorTypes[$errorType] = ($errorTypes[$errorType] ?? 0) + 1;

        // Count Meta API specific error codes
        if (isset($failed['meta_error_code'])) {
            $metaCode = $failed['meta_error_code'];
            $metaErrorCodes[$metaCode] = ($metaErrorCodes[$metaCode] ?? 0) + 1;
        }
    }

    return [
        'total_failures' => count($failedNumbers),
        'error_code_breakdown' => $errorCounts,
        'error_type_breakdown' => $errorTypes,
        'error_code_breakdown' => $metaErrorCodes,
        'most_common_error' => array_keys($errorCounts, max($errorCounts))[0] ?? null,
        'validation_errors' => $errorTypes['validation_error'] ?? 0,
        'sending_errors' => $errorTypes['sending_error'] ?? 0,
        'connection_errors' => $errorTypes['connection_error'] ?? 0,
        'service_errors' => $errorTypes['service_error'] ?? 0,
    ];
}

    protected function prepareRequestData($to, $customMessage, $requestType, $templaname, $dynamicValues, $mediaLink, $templateData, $ButtonValue, $fileName, $mediaID, $templateLanguage)
    {
        try {
            $payload = [
                'phoneNumber' => $to,
                'requestType' => 'template',
                'mediaID' => $mediaID,
                'fileName' => $fileName,
                'mediaLink' => filled($mediaID) ? $mediaID : $mediaLink,
                'mediaType' => filled($mediaID) ? 'id' : (is_numeric($mediaLink) ? 'id' : 'link'),
                'templateName' => $templaname,
                'templateLanguage' => $templateLanguage,
                'template' => $templateData,
                'customMessage' => $customMessage,
                'dynamicValues' => $dynamicValues,
                'dynamicButtonUrls' => $ButtonValue,
                'customType' => null, // or 'text' / 'image' etc. if not using templates
            ];
          	//return $payload;
            $response = Http::post('https://rtt.smsforyou.biz/api/generate-payload', $payload);

            if ($response->successful()) {
                return $response->json('payload'); // return the payload to send message
            } else {
                //  \Log::error('Failed to generate payload from Node.js: ' . $response->body());
                return null;
            }
        } catch (Exception $e) {
            //\Log::error('Error contacting Node.js API: ' . $e->getMessage());
            return 'Error contacting payload API: ' . $e->getMessage();
        }
    }

    protected function getTemplateData($reqType, $whatsapp_business_account_id, $templates, $waToken, $templateName)
    {
        if ($reqType == 'T') {
            $templatesApi = str_replace(':whatsapp_business_account_id:', $whatsapp_business_account_id, $templates);
            $templatesApi = str_replace(':waToken:', $waToken, $templatesApi);
            $header = null;
            $body = null;
            $footer = null;
            $buttons = null;
            $templateData = $this->client->get($templatesApi, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $waToken,
                ],
            ]);
            $responseBody = $templateData->getBody()->getContents();
            $data = json_decode($responseBody, true);
            //return $data;

            $foundTemplate = null;

            foreach ($data['data'] as $templateObject) {
                if ($templateObject['name'] === $templateName) {
                    $foundTemplate = $templateObject;
                    break; // Exit the loop once the template is found
                }
            }
            if (!$foundTemplate) {
                return ['error' => 'no template found'];
            }
            $Templatelanguage = $foundTemplate['language'];
            // else {
            if (isset($foundTemplate['components'])) {
                foreach ($foundTemplate['components'] as $section) {
                    switch ($section['type']) {
                        case 'HEADER':
                            $header = $section;
                            break;
                        case 'BODY':
                            $body = $section;
                            break;
                        case 'FOOTER':
                            $footer = $section;
                            break;
                        case 'BUTTONS':
                            $buttons = $section;
                            break;
                        // Handle any other section types if needed
                    }
                }
            }

            return compact('header', 'body', 'footer', 'buttons', 'Templatelanguage');
        }

        // }
    }
    //handle media whatsapp
    public function sendMediaMessage(Request $request)
    {
        $expectedParams = ['to', 'apikey'];
        $params = $request->query();
        $unexpectedParams = array_diff(array_keys($params), $expectedParams);

        if (!empty($unexpectedParams)) {
            $paramName = reset($unexpectedParams);
            return response()->json(['status' => false, 'error' => 'Invalid parameter(s)', 'invalid_params' => $paramName], 400);
        }
        $to = $request->query('to');
        $apikey = $request->query('apikey');
        $mediaFile = $request->file('file');
        $user = ApiKey::where('status', 'true')->where('key', $apikey)->with('user.userConfig')->first();

        if (!$user) {
            return response()->json(['status' => false, 'error' => 'Invalid API key'], 401);
        }
        if ($user) {
            if ($user->ip_auth == '1') {
                $ip = $request->ip();
                $userIPs = json_decode($user);
                $ipAddress = explode(',', $userIPs->ip_addresses);
                $ipAddress = array_filter($ipAddress, function ($value) {
                    return $value !== '';
                });

                if (!in_array($ip, $ipAddress)) {
                    return response()->json([
                        'error code' => 'SF7',
                        'status' => false,
                        'error' => 'IP Authentication failed'
                    ], 401);
                }
            }
        }
        if (empty($to) || !is_numeric($to) || (strlen($to) < 10 || strlen($to) > 12)) {
            return response()->json(['status' => false, 'error' => 'Invalid Mobile number'], 401);
        }
        if (!$mediaFile) {
            return response()->json(['status' => false, 'error' => 'Please choose your file'], 401);
        }
        $whatapp_phone_id = $user->user->userConfig->whatsapp_phone_id;
        $waToken = $user->user->userConfig->meta_access_token;
        $messageSendApi = env('WA_API_MESSAGES');
        $messagesApi = str_replace(':whatsapp_phone_id:', $whatapp_phone_id, $messageSendApi);

        return $this->prepareMediaRequest($whatapp_phone_id, $mediaFile, $waToken, $messagesApi, $to, $user, 'Manual');
    }

    protected function prepareMediaRequest($whatapp_phone_id, $mediaFile, $waToken, $messagesApi, $to, $user, $type)
    {
        //$originalName = $mediaFile->getClientOriginalName();

        $fileSize = $mediaFile->getSize();
        $fileMimeType = $mediaFile->getMimeType();
        // if ($fileMimeType !== 'application/pdf') {
        //     return response()->json([
        //         'status' => false,
        //         'message' => 'File must be a PDF.',
        //     ], 415);
        // }

        // Check if the file size is within the allowed limit (100MB)
        if ($fileSize > 100 * 1024 * 1024) {
            return response()->json([
                'status' => false,
                'message' => 'PDF size must be less than 100MB.',
            ], 415);
        }
        //return response()->json(['user' => $fileMimeType], 200);
        $client = $this->client;
        $mediaApi = env('WA_API_MEDIA', env('WA_API_MESSAGES', ''));
        $url = str_replace(':whatsapp_phone_id:', $whatapp_phone_id, $mediaApi);
        if (strpos($url, '/messages') !== false) {
            $url = str_replace('/messages', '/media', $url);
        }

        $formData = [
            [
                'name' => 'type',
                // 'contents' => 'application/pdf'
                'contents' => 'image/jpeg'
            ],
            [
                'name' => 'messaging_product',
                'contents' => 'whatsapp'
            ],
            [
                'name' => 'file',
                'contents' => fopen($mediaFile->path(), 'r'),
                'filename' => $mediaFile->getClientOriginalName(),
            ]
        ];
        try {
            $response = $client->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $waToken,
                ],
                'multipart' => $formData,
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();
            // return response()->json(['data'=>json_decode($body)->id], 200);
            if ($type == 'Custom') {
                return ['id' => json_decode($body)->id, 'filename' => $mediaFile->getClientOriginalName()];
            } else {
                return $this->handleSendMediaMessage($to, json_decode($body)->id, $messagesApi, $waToken, $mediaFile->getClientOriginalName(), $user);
            }
        } catch (RequestException $e) {
            // Handle request errors
            if ($e->hasResponse()) {
                $statusCode = $e->getResponse()->getStatusCode();
                $body = $e->getResponse()->getBody()->getContents();
                return ['error' => $body, 'status_code' => $statusCode];
            } else {
                return ['error' => $e->getMessage()];
            }
        }

    }
    protected function handleSendMediaMessage($to, $mediaID, $messagesApi, $waToken, $fileName, $user)
    {
        try {
            $data = [
                "messaging_product" => "whatsapp",
                "to" => $to,
                "type" => "document",
                "document" => [
                    "id" => $mediaID,
                    "filename" => $fileName
                ]
            ];
            $response = $this->client->post($messagesApi, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $waToken,
                ],
                'body' => json_encode($data),
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody()->getContents();
            $response = json_decode($responseBody);

            if ($response->messages[0]->id) {

                //$this->MakeOutReport($to, $user->user->whatsapp_number, $response->messages[0]->id, $user->user->id);
                $this->MakeOutReport(
                    $to,
                    $user->user->whatsapp_number,
                    $response->messages[0]->id,
                    $user->user->id,
                    templateName: null,
                    mediaId: $mediaID,
                    mediaUrl: null,
                    bodyValues: null,
                    buttonValues: null
                );
                return response()->json([
                    'status' => true,
                    'message' => 'Message submitted successfully'
                    //'name'=>$fileName
                ], 200);
            }
            // Handle the API response
            if ($statusCode >= 200 && $statusCode < 300) {
                // Successful response
                return response()->json(['success' => $responseBody]);
                // return $responseBody;
            } else {
                // Error response
                $error = throw new Exception("API request failed with status code: $statusCode, response: $responseBody");
                return response()->json(['error 1' => $error]);
            }
        } catch (GuzzleException $e) {
            // $error = throw new Exception("Guzzle exception occurred: {$e->getMessage()}");
            return response()->json(['error 2' => $e->getMessage()]);
        } catch (Exception $e) {
            // Handle other exceptions
            // throw $e;
            return response()->json(['error 2' => $e]);
        }
    }

    protected function MakeOutReport($to, $whatapp_number, $messageID, $user_id, $templateName, $mediaId, $mediaUrl, $bodyValues, $buttonValues,$reportId)
    {
        $out_report = new OutReport();
        $out_report->user_id = $user_id;
        $out_report->display_phone_number = $whatapp_number;
      	$out_report->status = 'pending';
        $out_report->status_id = $messageID;
        $out_report->recipient_id = $to;
        $out_report->save();
      	$this->NewChat($user_id, $templateName, $messageID, 'template', null, $out_report->id,$reportId);
        return $this->MakeApiReport(
            $templateName,
            $mediaId,
            $mediaUrl,
            $bodyValues,
            $buttonValues,
            $out_report->id
        );
        return response()->json(['response' => $out_report], 200);
    }
    private function NewChat($user_id, $message, $message_id, $type, $agent_id, $out_report_id,$reportId)
    {
        $chat = new ChatHistory();
        $chat->user_id = $user_id;
        $chat->message = $message;
        $chat->message_id = $message_id;
        $chat->type = $type;
        $chat->agent_id = $agent_id;
        $chat->out_report_id = $out_report_id;
        $chat->reply_id = null;
        $chat->report_id = $reportId;
        $chat->save();
        return response()->json(['status' => true], 200);
    }
    protected function MakeApiReport($templateName, $mediaId, $mediaUrl, $bodyValues, $buttonValues, $report_id)
    {
        try {
            $report = new ApiTemplateReport();
            $report->report_id = $report_id;
            $report->template_name = $templateName;
            $report->media_id = $mediaId;
            $report->media_url = $mediaUrl;
            $report->body_values = json_encode($bodyValues);
            $report->button_values = json_encode($buttonValues);
            $report->save();

            return response()->json(['status' => true, 'message' => 'Report created successfully', 'report' => $report], 201);
        } catch (Exception $e) {
            return response()->json(['status' => false, 'error' => 'Failed to create report: ' . $e->getMessage()], 500);
        }
    }

}

<?php

namespace App\Http\Controllers\Messaging;

use App\Http\Controllers\Controller;
use App\Models\Campaign\Campaign;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function sendMessages(Request $request)
    {
        $validated = $request->validate([
            'numbers' => 'required|array',
            'requestType' => 'required|string',
            'templateName' => 'nullable|string',
            'templateLanguage' => 'nullable|string',
            'template' => 'nullable|array',
            'customMessage' => 'nullable|string',
            'dynamicValues' => 'nullable|array',
            'dynamicButtonUrl' => 'nullable|array',
            'api' => 'required|string',
            'waToken' => 'required|string',
            'authToken' => 'required|string',
            'campaignName' => 'required|string',
        ]);

        // Create a campaign record
        $campaign = Campaign::create([
            'name' => $validated['campaignName'],
            'user_id' => auth()->id(), // Or however you manage user ID
        ]);

        // Dispatch jobs to handle sending messages
        foreach ($validated['numbers'] as $number) {
            SendMessageJob::dispatch($number, $validated, $campaign->id)
                ->onQueue('messages');
        }

        return response()->json(['message' => 'Messages are being sent.'], 200);
    }

    public function ChatbotVerifyOTP($otp){
      	$options = [
            ['accountnumber'=>'1234'],
            ['accountnumber'=>'4567'],
            ['accountnumber'=>'7890'],
            ['accountnumber'=>'1112'],
            ['accountnumber'=>'1213'],
            ['accountnumber'=>'1314'],
        ];
        $token = 'Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJhdWQiOiIxIiwianRpIjoiNWVhMmIxY2Q3ODM2NDA0MTgwZmRmMTEyMGU4ODk1Yjg0NTUwNTg1ZTMwZjk4ODYwMWZmMmY4MDZlM2VjYmFhNTVhNjY3YjE1YWJkNjkzMmEiLCJpYXQiOjE3MzY0OTA0ODkuNjU5NDU1LCJuYmYiOjE3MzY0OTA0ODkuNjU5NDU2LCJleHAiOjE3NjgwMjY0ODkuNjU4NTg2LCJzdWIiOiIxIiwic2NvcGVzIjpbXX0.h-M-vao3SHYqAvHAtqLQjeQ-mNv7OMoZlXWNM0__LRtVao-8x9BVvyAT0HewFBHgy8jqr9XI6j1OT1d70EUKOzA_GzLdpQhc_qPDrZWIseYKfJT8-x9gOH-7ylF1Ly53_xBpiCnSOcMTeY4o8K6D-6BN_1U5x3H618CHWGj2XBcbWQhMnyZCyXDwZSu2MlqeOafVn0muhyGRfneoDjDdxj-0VW5VRNw7Ze7qhMK2YUUE8nFR_JPLTmrHr6NOt5df_uA06x5Rsl2tqg_Kia0KzVqV86L7FZJWYAAnK3lJcHvQxl6vY-3Q_FV2RmyOhSQHKq72K2zCjLG7LBJ8JHbeB1r1fDPZl6I4wsIEfOGGedmMcDiysaiIEFklT5qZXaf31KYbWXN6UiZJv_J0mVpNRV2Y5DeEthNGlsA6xLYaWtfinDlCiv42o2cNdph7i1Sz8tbL-f3l-O6aMeWHTGCp-YqrMhU2OcJdnZZ0XuVBIHjYIlElcH9t8sJWMoL1BnPrkPmTmIso2Q0NUgLnyaI4MKHXA-E1kei7C49zchWdbCaFJF-dJqMrwe2rl09l5InOFrCQMs1oxofR6rB8th7FDe1pckN8_pqMVwuTVUrkaFG7clEeBXRjpZAijOJomLi62M5YJ5wNY4Y7Z4ECfQ5zEog0z--e2UrcEo5aN2EfMFA';
		$user = ['name'=>'John','token'=>$token,'message' =>$otp,'auth_token'=>'dfwfd432fedf','AccountDT'=>$options];
        return response()->json(['status'=>true,'data' =>$user], 200);
    }
}

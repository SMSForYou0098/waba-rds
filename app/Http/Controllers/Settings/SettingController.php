<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Settings\Setting;
use App\Models\Report\Logdata;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SettingController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $setting = Setting::first();
        return response()->json(['setting' => $setting], 200);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {

    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $setting = Setting::findOrFail('1');
        try {
            $setting = Setting::findOrFail(1);

            if ($request->has('logo')) {
                $logo = $request->file('logo');
                $setting->logo = $this->storeFile($logo);
            }

            if ($request->has('login_bg')) {
                $loginBg = $request->file('login_bg');
                $setting->login_bg = $this->storeFile($loginBg);
            }

            if ($request->has('logs')) {
                $setting->logs = $request->logs == "true";
            }

            if ($request->has('maintenance')) {
                $setting->maintenance = $request->maintenance == "true";
            }
            if ($request->has('impersonate_otp')) {
                $setting->impersonate_otp = $request->impersonate_otp == "true";
            }
            if ($request->has('favicon')) {
                $setting->favicon = $request->favicon;
            }

            if ($request->has('blocked_numbers')) {
                $setting->blocked_numbers = $request->blocked_numbers;
            }

            if ($request->has('short_url')) {
                $setting->short_url = $request->short_url;
            }

            if ($request->has('allow_register_user')) {
                $setting->allow_register_user = $request->allow_register_user;
            }

            $setting->save();

            return response()->json(['setting' => $setting], 200);
        } catch (ModelNotFoundException $e) {
            // Handle the case where the Setting model is not found
            return response()->json(['error' => 'Setting not found'], 404);
        } catch (ValidationException $e) {
            // Handle validation errors, if any
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            // Handle other unexpected errors
            return response()->json(['error' => 'Something went wrong' . $e->getMessage()], 500);
        }
    }

    protected function storeFile($file, $storageDisk = 'uploads')
    {
        $path = $file->store('settings', $storageDisk);
        $url = Storage::disk($storageDisk)->url($path);
        return $url;
    }
    public function storeSMSCongif(Request $request)
    {
        $setting = Setting::findOrFail('1');
        $setting->sms_senderId = $request->sms_senderId;
        $setting->sms_apiKey = $request->sms_apiKey;
        $setting->save();
        return response()->json(['status' => true, 'message' => "Chages updated successfully"], 200);
    }
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
    public function updateLogdataReprocessedAt(Request $request)
    {
        $request->validate([
            'message_id' => 'required|string',
        ]);

        $messageId = $request->input('message_id');
        $updated = Logdata::where('message_id', $messageId)
            ->update(['reprocessed_at' => now()]);

        if ($updated) {
            return response()->json(['status' => true, 'message' => 'Reprocessed time updated successfully.']);
        } else {
            return response()->json(['status' => false, 'message' => 'No logdata found for this message_id.'], 404);
        }
    }
}


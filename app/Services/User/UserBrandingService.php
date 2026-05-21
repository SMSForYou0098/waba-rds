<?php

namespace App\Services\User;

use App\Models\Settings\BrandingConfiguration;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class UserBrandingService
{
    /**
     * @return array{status: string, message: string, data?: BrandingConfiguration}
     */
    public function getByHostUrl(string $hostUrl): array
    {
        $brandingConfig = BrandingConfiguration::where('host_url', $hostUrl)->first();

        if (! $brandingConfig) {
            return [
                'status' => 'false',
                'message' => 'No branding configuration found for this host URL',
                'http_status' => 404,
            ];
        }

        $user = User::find($brandingConfig->user_id);

        if (! $user) {
            return [
                'status' => 'false',
                'message' => 'User not found for this branding configuration',
                'http_status' => 404,
            ];
        }

        if (! $user->white_lable) {
            return [
                'status' => 'false',
                'message' => 'White label is not enabled for this user',
                'http_status' => 403,
            ];
        }

        return [
            'status' => 'true',
            'message' => 'Branding configuration retrieved successfully',
            'data' => $brandingConfig,
            'http_status' => 200,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function syncForUser(User $user, Request $request): array
    {
        try {
            $imagePath = null;
            if ($request->hasFile('white_label_logo')) {
                $imagePath = $this->storeFile($request->file('white_label_logo'));
            } else {
                $imagePath = $request->white_label_logo;
            }

            $loginBgPath = null;
            if ($request->hasFile('login_bg')) {
                $loginBgPath = $this->storeFile($request->file('login_bg'));
            } else {
                $loginBgPath = $request->login_bg;
            }

            $brandingConfig = BrandingConfiguration::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'logo' => $imagePath ?? null,
                    'terms' => $request->terms_url ?? null,
                    'privacy' => $request->privacy_url ?? null,
                    'host_url' => $request->host_url,
                    'copyright' => $request->copyright,
                    'login_bg' => $loginBgPath ?? null,
                ]
            );

            return [
                'branding_status' => 'success',
                'branding_message' => 'Branding configuration processed successfully',
                'branding_config_id' => $brandingConfig->id,
            ];
        } catch (\Exception $e) {
            return [
                'branding_status' => 'error',
                'branding_message' => 'Error processing branding configuration: '.$e->getMessage(),
            ];
        }
    }

    public function storeFile(UploadedFile $file, string $storageDisk = 'uploads'): string
    {
        $path = $file->store('brand_media', $storageDisk);

        return Storage::disk($storageDisk)->url($path);
    }
}

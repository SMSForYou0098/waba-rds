<?php

namespace App\Console\Commands\Media;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Media\Media;
use App\Models\Settings\UserConfig;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
class ReuploadMedia extends Command
{
    protected $signature = 'media:reupload';
    protected $description = 'Reupload media files to the storage';

    public function handle()
    {
        // Find users with the "Auto Reupload Media" permission (directly or via role)
        $users = User::all()->filter(function ($user) {
            return $user->hasPermissionTo('Auto Reupload Media', 'api');
        });

        $client = new Client();

        foreach ($users as $user) {
            // Find media older than 30 days for each user
            // Find media older than 30 days for each user
            $oldMediaQuery = Media::withTrashed()
                ->where('user_id', $user->id)
                ->where(function ($query) {
                    $date = Carbon::now()->subDays(30);
                    $query->where(function ($q) use ($date) {
                        $q->whereNotNull('updated_at')
                            ->where('updated_at', '<', $date);
                    })->orWhere(function ($q) use ($date) {
                        $q->whereNull('updated_at')
                            ->where('created_at', '<', $date);
                    });
                });

            // Only add delete_type condition if deleted_at is not null (i.e., soft deleted)
            $oldMediaQuery->where(function ($query) {
                $query->whereNull('deleted_at')
                    ->orWhere(function ($q) {
                        $q->whereNotNull('deleted_at')
                            ->where('delete_type', 'auto');
                    });
            });

            $oldMedia = $oldMediaQuery->get();
          	$mediaIds = $oldMedia->pluck('id')->toArray();
            $this->info("User {$user->id} fetched media IDs: " . implode(', ', $mediaIds));
            $this->info("User {$user->id} has {$oldMedia->count()} media older than 30 days.");

            // Get user config
            $userConfig = UserConfig::where('user_id', $user->id)->first();
            if (!$userConfig || !$userConfig->whatsapp_phone_id || !$userConfig->meta_access_token) {
                $this->warn("User {$user->id} missing whatsapp_phone_id or waToken.");
                continue;
            }
            $whatsappPhoneId = $userConfig->whatsapp_phone_id;
            $waToken = $userConfig->meta_access_token;
            $url = "https://graph.facebook.com/v20.0/{$whatsappPhoneId}/media";

            foreach ($oldMedia as $media) {
                $filePath = $media->path;
                $filePath = str_replace(
                    'https://waba.smsforyou.biz/storage/uploads/media/',
                    '/home/smsforyou-waba/htdocs/waba.smsforyou.biz/public/storage/uploads/media/',
                    $media->path
                );
                if (!File::exists($filePath)) {
                    $this->warn("File not found: $filePath");
                    continue;
                }
                $filename = basename($filePath);
                $mimeType = mime_content_type($filePath);
                try {
                    $oldMediaId = $media->media_id;
                    $response = $client->post($url, [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $waToken,
                            'Accept' => 'application/json',
                        ],
                        'multipart' => [
                            [
                                'name' => 'file',
                                'contents' => fopen($filePath, 'r'),
                                'filename' => $filename,
                            ],
                            [
                                'name' => 'type',
                                'contents' => $mimeType,
                            ],
                            [
                                'name' => 'filename',
                                'contents' => $filename,
                            ],
                            [
                                'name' => 'messaging_product',
                                'contents' => 'whatsapp',
                            ],
                        ],
                    ]);
                    $responseData = json_decode($response->getBody(), true);
                    if (isset($responseData['id'])) {
                        $media->media_id = $responseData['id'];
                        $media->deleted_at = null;
                        $media->size = File::size($filePath);
                        $media->save();
                      
                      	DB::table('chatbots')
                            ->where('media_id', $oldMediaId)
                            ->update(['media_id' => $responseData['id']]);
                        $this->info("Media {$media->id} reuploaded and updated with new media_id for user {$user->id}: " . $responseData['id']);
                    } else {
                        $this->warn("Media {$media->id} reuploaded but no media_id returned for user {$user->id}.");
                    }
                } catch (\Exception $e) {
                    $this->error("Reupload failed for media {$media->id} (user {$user->id}): " . $e->getMessage());
                }
            }
        }
    }
}

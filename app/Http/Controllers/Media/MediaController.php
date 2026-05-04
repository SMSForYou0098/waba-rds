<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\Media\Media;
use App\Models\Report\Report;
use App\Models\User;
use App\Services\Media\UrlPreviewService;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use DB;
use DOMDocument;
use DOMXPath;
use Str;
class MediaController extends Controller
{
    private const CACHE_TTL = 3600; // 1 hour
    private const MEDIA_CACHE_TTL = 7200; // 2 hours

    protected $urlPreviewService;

    public function __construct(UrlPreviewService $urlPreviewService)
    {
        $this->urlPreviewService = $urlPreviewService;
    }
    public function index($id)
    {
        $media = Media::where('user_id', $id)->withTrashed()->get();
        return response()->json(['media' => $media]);
    }

    public function getUserStorage($userId)
    {
        $user = User::findOrFail($userId);

        $totalStorage = $user->storage_limit;

        $totalStorageKB = $this->convertBytesToHuman($totalStorage);

        $usedStorage = Media::withTrashed()->where('user_id', $userId)->sum('size');

        $usedStorageKB = $this->convertBytesToHuman($usedStorage);

        $remainingStorage = $totalStorage - $usedStorage;

        $remainingStorageKB = $this->convertBytesToHuman($remainingStorage);

        return response()->json([
            'user_id' => $userId,
            'storage_limit' => $totalStorageKB,
            'used_storage' => $usedStorageKB,
            'remaining_storage' => $remainingStorageKB
        ]);
    }
    private function convertBytesToHuman($bytes)
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' Bytes';
        }
    }

    public function store(Request $request)
    {
        // Define validation rules for each file type
        $rules = [
            'audio' => 'nullable|file|mimes:audio/*|max:16384', // 16 MB in bytes
            'document' => 'nullable|file|mimes:pdf,txt,doc,docx|max:102400', // 100 MB in bytes
            'image' => 'nullable|file|mimes:jpeg,png,gif|max:5120', // 5 MB in bytes
            'video' => 'nullable|file|mimes:video/*|max:16384', // 16 MB in bytes
        ];

        // Validate the request
        $request->validate($rules);
        //return response()->json(['status' => $request->all()]);
        try {
            // Ensure the file exists in the request
            if (!$request->hasFile('file')) {
                return response()->json(['error' => 'No file was uploaded.'], 422);
            }

            $file = $request->file('file');
            $originalName = $file->getClientOriginalName();
            $fileSize = $file->getSize();
            $fileMimeType = $file->getMimeType();

            // Store the file and get the URL
            $imagePath = $this->storeFile($file);

            // Save media information to the database
            $media = new Media();
            $media->user_id = $request->user_id;
            $media->media_id = $request->media_id;
            $media->name = $originalName;
            $media->size = $fileSize;
            $media->type = $fileMimeType;
            $media->path = $imagePath;
            $media->save();

            return response()->json(['status' => true, 'message' => 'Media file uploaded successfully']);
        } catch (ValidationException $e) {
            // Validation failed, return error response
            return response()->json(['error' => $e->validator->errors()->first()], 422);
        } catch (\Exception $e) {
            // Catch any other unexpected errors
            return response()->json(['error' => 'An unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }

    protected function storeFile($file, $storageDisk = 'uploads')
    {
        $path = $file->store('media', $storageDisk);
        $url = Storage::disk($storageDisk)->url($path);
        return $url;
    }

    public function restore($id, $mediaId)
    {
        $media = Media::withTrashed()->findOrFail($id);
        if ($media) {
            $media->deleted_at = null; // Reset deleted_at to null
            $media->media_id = $mediaId; // Update media_id if needed
            $media->save();
            return response()->json(['status' => true, 'message' => 'Media File Restored Successfully']);
        } else {
            return response()->json(['status' => false, 'message' => 'File not found: '], 400);
        }
    }


    public function destroy(string $id)
    {
        $media = Media::findOrFail($id);
        $filePath = str_replace(
            'https://waba.smsforyou.biz/storage/uploads/media/',
            '/home/smsforyou-waba/htdocs/waba.smsforyou.biz/public/storage/uploads/media/',
            $media->path
        );
        if (File::exists($filePath)) {
            //File::delete($filePath);
            $media->delete();
            return response()->json(['status' => true, 'message' => 'Media File Deleted Successfully']);
        } else {
            return response()->json(['status' => false, 'message' => 'File not found: ' . $filePath], 400);
        }
    }
    public function getFile(Request $request, $id)
    {
        try {

            // Find the media file (including soft deleted files)
            $media = Media::withTrashed()->findOrFail($id);
            $media->deleted_at = null;
            $media->created_at = now();
            $media->save();
            // Convert the file URL to a local path
            $filePath = str_replace(
                'https://waba.smsforyou.biz/storage/uploads/media/',
                '/home/smsforyou-waba/htdocs/waba.smsforyou.biz/public/storage/uploads/media/',
                $media->path
            );

            // Check if file exists
            if (!File::exists($filePath)) {
                return response()->json([
                    'status' => false,
                    'message' => 'File not found'
                ], 404);
            }

            // Determine file mime type
            $mimeType = mime_content_type($filePath);

            // Generate response
            //return response()->json($media->deleted_at = null);
            return response()->file($filePath, [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'inline; filename="' . $media->name . '"'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error retrieving file: ' . $e->getMessage()
            ], 500);
        }
    }
    public function permanentDeleteFile(string $id)
    {
        $media = Media::withTrashed()->findOrFail($id);
        $filePath = str_replace(
            'https://waba.smsforyou.biz/storage/uploads/media/',
            '/home/smsforyou-waba/htdocs/waba.smsforyou.biz/public/storage/uploads/media/',
            $media->path
        );
        if (File::exists($filePath)) {
            File::delete($filePath);
            $media->forceDelete();
            return response()->json(['status' => true, 'message' => 'Media File Deleted Successfully']);
        } else {
            return response()->json(['status' => false, 'message' => 'Something Went Wrong'], 400);
        }
    }

    public function retrieveImageFromMeta(Request $request)
    {
        $apiUrl = $request->input('url');
        $token = $request->input('token');
        $parentNumber = $request->input('parent_number');
        $childNumber = $request->input('child_number');
        $reportID = $request->input('report_id');

        try {
            $client = new Client();
            $response = $client->get($apiUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
            ]);
            $fileContent = $response->getBody()->getContents();

            // Verify if the blob data is valid
            if (empty($fileContent)) {
                return response()->json([
                    'status' => false,
                    'message' => 'No data received from the API.',
                ], 400);
            }
            $fileUrl = $this->storeReportMedia($fileContent, $parentNumber, $childNumber, $reportID);
            return $this->updateMediaUrl($reportID, $fileUrl);
        } catch (RequestException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve image from the API: ' . $e->getMessage(),
            ], 500);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An unexpected error occurred: ' . $e->getMessage(),
            ], 500);
        }
    }

    protected function storeReportMedia($fileContent, $parentNumber, $childNumber)
    {
        $fileName = now()->timestamp . '_' . Str::random(10) . '.jpg';
        $directoryPath = "reports_media/{$parentNumber}/{$childNumber}";
        if (!Storage::disk('uploads')->exists($directoryPath)) {
            Storage::disk('uploads')->makeDirectory($directoryPath);
        }
        $filePath = $directoryPath . '/' . $fileName;
        Storage::disk('uploads')->put($filePath, $fileContent);
        $fileUrl = Storage::disk('uploads')->url($filePath);
        return $fileUrl;
    }

    public function storeFromMeMedia(Request $request)
    {
        $fileContent = $request->file('file')->get();
        $parentNumber = $request->input('parent_number');
        $childNumber = $request->input('child_number');
        $fileUrl = $this->storeReportMedia($fileContent, $parentNumber, $childNumber);
        return response()->json(['message' => 'Media file uploaded successfully', 'media_url' => $fileUrl], 200);
    }

    private function updateMediaUrl($id, $media_url)
    {
        try {
            $report = Report::find($id);
            if (!$report) {
                return response()->json(['error' => 'Report not found'], 404);
            }
            $report->media_url = $media_url;
            $report->save();
            return response()->json(['message' => 'Media URL updated successfully', 'media_url' => $media_url], 200);
        } catch (Exception $e) {
            return response()->json(['error' => 'An error occurred while updating the media URL: ' . $e->getMessage()], 500);
        }
    }



    public function getFilesByParentAndChild(Request $request, $parentNumber, $childNumber)
    {
        try {
            $cacheKey = "media_data:{$parentNumber}:{$childNumber}";

            $allData = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($parentNumber, $childNumber) {
                // Get file data from storage
                $fileData = $this->getStorageFiles($parentNumber, $childNumber);

                // Get URLs from chat history
                $chatUrls = $this->getChatUrlsFromReports($parentNumber, $childNumber);

                return [
                    'storage_files' => $fileData,
                    'chat_urls' => $chatUrls,
                    'total_storage_files' => count($fileData),
                    'total_chat_urls' => count($chatUrls),
                    'cached_at' => now()->toDateTimeString()
                ];
            });

            return response()->json([
                'status' => true,
                'message' => 'Files and chat URLs retrieved successfully.',
                'data' => $allData
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An unexpected error occurred: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function getStorageFiles($parentNumber, $childNumber)
    {
        $cacheKey = "storage_files:{$parentNumber}:{$childNumber}";

        return Cache::remember($cacheKey, self::MEDIA_CACHE_TTL, function () use ($parentNumber, $childNumber) {
            $directoryPath = "reports_media/{$parentNumber}/{$childNumber}";

            if (!Storage::disk('uploads')->exists($directoryPath)) {
                return [];
            }

            $files = Storage::disk('uploads')->files($directoryPath);

            $fileData = [];
            foreach ($files as $file) {
                try {
                    $fileData[] = [
                        'file_url' => Storage::disk('uploads')->url($file),
                        'file_type' => mime_content_type(Storage::disk('uploads')->path($file)) ?: 'application/octet-stream',
                        'file_name' => basename($file),
                        'file_size' => Storage::disk('uploads')->size($file),
                        'source' => 'storage',
                        'last_modified' => Storage::disk('uploads')->lastModified($file)
                    ];
                } catch (Exception $e) {
                    // Skip files that can't be processed
                    continue;
                }
            }

            return $fileData;
        });
    }
    private function getChatUrlsFromReports($parentNumber, $childNumber)
    {
        $cacheKey = "chat_urls:{$parentNumber}:{$childNumber}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($parentNumber, $childNumber) {
            try {
                // Get message IDs
                $messageIds = $this->getMessageIds($parentNumber, $childNumber);

                if (empty($messageIds)) {
                    return [];
                }

                // Get chat messages
                $chatMessages = $this->getChatMessages($messageIds);

                if ($chatMessages->isEmpty()) {
                    return [];
                }

                // Extract URLs and get previews
                return $this->processUrlsFromMessages($chatMessages);

            } catch (Exception $e) {
                \Log::error('Error fetching chat URLs: ' . $e->getMessage());
                return [];
            }
        });
    }
    private function processUrlsFromMessages($chatMessages)
    {
        $urlData = [];
        $allUrls = [];

        // First, collect all unique URLs
        foreach ($chatMessages as $chat) {
            $urls = $this->urlPreviewService->extractUrls($chat->message);
            foreach ($urls as $url) {
                if (!in_array($url, $allUrls)) {
                    $allUrls[] = $url;
                }
            }
        }

        // Get previews for all URLs at once (with caching)
        $urlPreviews = $this->urlPreviewService->getMultiplePreviews($allUrls);

        // Now process each message
        foreach ($chatMessages as $chat) {
            $urls = $this->urlPreviewService->extractUrls($chat->message);

            foreach ($urls as $url) {
                $urlData[] = [
                    'url' => $url,
                    'message_id' => $chat->message_id,
                    'full_message' => $chat->message,
                    'sent_at' => $chat->created_at,
                    'url_type' => $this->urlPreviewService->detectUrlType($url),
                    'source' => 'chat_history',
                    'preview' => $urlPreviews[$url] ?? $this->urlPreviewService->getBasicPreview($url)
                ];
            }
        }

        return $urlData;
    }

    private function getMessageIds($parentNumber, $childNumber)
    {
        $cacheKey = "message_ids:{$parentNumber}:{$childNumber}";

        return Cache::remember($cacheKey, self::CACHE_TTL * 2, function () use ($parentNumber, $childNumber) {
            return DB::table('out_reports')
                ->select('status_id')
                ->where('display_phone_number', $parentNumber)
                ->where('recipient_id', $childNumber)
                ->whereNotNull('status_id')
                ->pluck('status_id')
                ->toArray();
        });
    }
    private function getChatMessages($messageIds)
    {
        return DB::table('chat_histories')
            ->select('message_id', 'message', 'created_at')
            ->where('type', 'text')
            ->whereIn('message_id', $messageIds)
            ->whereNotNull('message')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    private function getBasicPreview($url)
    {
        $domain = parse_url($url, PHP_URL_HOST);
        $siteName = str_replace('www.', '', $domain);

        return [
            'title' => $siteName,
            'description' => 'Link to ' . $siteName,
            'image' => null,
            'favicon' => 'https://www.google.com/s2/favicons?domain=' . $domain,
            'site_name' => $siteName,
            'domain' => $domain,
            'has_preview' => false
        ];
    }
    private function detectUrlType($url)
    {
        // Get file extension
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        // Image extensions
        $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico'];
        if (in_array($extension, $imageExts)) {
            return 'image';
        }

        // Video extensions
        $videoExts = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv', '3gp'];
        if (in_array($extension, $videoExts)) {
            return 'video';
        }

        // Audio extensions
        $audioExts = ['mp3', 'wav', 'ogg', 'aac', 'flac', 'm4a'];
        if (in_array($extension, $audioExts)) {
            return 'audio';
        }

        // Document extensions
        $docExts = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'];
        if (in_array($extension, $docExts)) {
            return 'document';
        }

        // Archive extensions
        $archiveExts = ['zip', 'rar', '7z', 'tar', 'gz'];
        if (in_array($extension, $archiveExts)) {
            return 'archive';
        }

        // Check for common platforms
        $domain = parse_url($url, PHP_URL_HOST);
        if ($domain) {
            if (strpos($domain, 'youtube.com') !== false || strpos($domain, 'youtu.be') !== false) {
                return 'youtube_video';
            }
            if (strpos($domain, 'vimeo.com') !== false) {
                return 'vimeo_video';
            }
            if (strpos($domain, 'instagram.com') !== false) {
                return 'instagram';
            }
            if (strpos($domain, 'facebook.com') !== false || strpos($domain, 'fb.com') !== false) {
                return 'facebook';
            }
            if (strpos($domain, 'twitter.com') !== false || strpos($domain, 'x.com') !== false) {
                return 'twitter';
            }
            if (strpos($domain, 'linkedin.com') !== false) {
                return 'linkedin';
            }
        }

        return 'web_link';
    }



    public function storeold(Request $request)
    {
        // Define validation rules for each file type
        $rules = [
            'audio' => 'nullable|file|mimes:audio/*|max:16384', // 16 MB in bytes
            'document' => 'nullable|file|mimes:application/pdf,text/plain,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document|max:102400', // 100 MB in bytes
            'image' => 'nullable|file|mimes:jpeg,png,gif|max:5120', // 5 MB in bytes
            'video' => 'nullable|file|mimes:video/*|max:16384', // 16 MB in bytes
        ];

        // Validate the request
        try {
            // Validate the request
            $request->validate($rules);
            $file = $request->file('file');
            $originalName = $file->getClientOriginalName();
            $fileSize = $file->getSize();
            $fileMimeType = $file->getMimeType();

            function storeFile($file, $storageDisk = 'uploads')
            {
                $path = $file->store('media', $storageDisk);
                $url = Storage::disk($storageDisk)->url($path);
                return $url;
            }
            $imagePath = storeFile($file);

            $media = new Media();
            $media->user_id = $request->user_id;
            $media->media_id = $request->media_id;
            $media->name = $originalName;
            $media->type = $fileMimeType;
            $media->path = $imagePath;
            $media->save();
            return response()->json(['status' => true, 'message' => 'Media file Uploded Successfully']);
        } catch (ValidationException $e) {
            // Validation failed, return error response
            return response()->json(['error' => $e->validator->errors()->first()], 422);
        }

    }



}

<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class FacebookUploadController extends Controller
{
    public function getSessionId(Request $request)
    {
        // Retrieve the file from the request
        $file = $request->file('media');

        if ($file) {
            $filename = $file->getClientOriginalName();
            $filetype = $file->getClientMimeType();
            $filesize = $file->getSize();

            // Replace these with your actual values
            $file = $request->file('media');
            $waToken = $request->input('waToken');
            $appId = $request->input('appId');


            $url = "https://graph.facebook.com/v20.0/{$appId}/uploads?file_name={$filename}&file_length={$filesize}&file_type={$filetype}&access_token={$waToken}";

            try {
                // Create a Guzzle client
                $client = new Client();
                $response = $client->post($url);
                $body = json_decode($response->getBody());

                if (isset($body->id)) {
                    $sessionId = $body->id;
                    return $this->getHandlerHandle($sessionId, $file, $waToken);
                }

            } catch (RequestException $e) {
                // Handle the error
                return response()->json(['error' => 'Error fetching session ID',$e->getMessage()], 500);
            }
        } else {
            return response()->json(['error' => 'No file provided'], 400);
        }
    }

    private function getHandlerHandle($uploadSessionId, $file, $waToken)
    {
        $url = "https://graph.facebook.com/v20.0/{$uploadSessionId}";

        try {
            // Create a Guzzle client
            $client = new Client();

            // Prepare the form data
            $formData = [
                [
                    'name'     => 'data-binary',
                    'contents' => fopen($file->getPathname(), 'r'),
                    'filename' => $file->getClientOriginalName(),
                ]
            ];

            $response = $client->post($url, [
                'headers' => [
                    'Authorization' => "OAuth {$waToken}",
                    'file_offset' => 0,
                    'Content-Type' => 'multipart/form-data',
                ],
                'multipart' => $formData,
            ]);

            $body = json_decode($response->getBody(), true);

            // Handle the response
            return response()->json($body);

        } catch (RequestException $e) {
            // Handle the error
            return response()->json(['error' => 'Error uploading file'], 500);
        }
    }
}

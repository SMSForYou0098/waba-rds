<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Auth\ApiKey;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class DeveloperController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index($id)
    {
        $apiKey = ApiKey::where('user_id',$id)->where('status', 'true')->get();
        return response()->json(['api_key' => $apiKey], 200);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $existingApiKey = ApiKey::where('user_id', $request->user_id)->where('status', 'true')->exists();
            if ($existingApiKey) {
                ApiKey::where('user_id', $request->user_id)->update(['status' => 'false']);
            }

            $apiKey = new ApiKey();
            $apiKey->key = $request->key;
            $apiKey->user_id = $request->user_id;
            $apiKey->status = 'true';
            $apiKey->save();

            return response()->json(['message' => 'API key created successfully'], 200);
        } catch (QueryException $e) {

            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {

            return response()->json(['error' => 'An error occurred: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
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
        try {

            // return response()->json(['message' => $request->row['id']], 200);
            $apiKey = ApiKey::findOrFail($id);
            if (isset($request->row)) {
                if (isset($request->row['key'])) {
                    $apiKey->key = $request->row['key'];
                }
                if (isset($request->row['user_id'])) {
                    $apiKey->user_id = $request->row['user_id'];
                }
                if (isset($request->row['ip_addresses'])) {
                    $apiKey->ip_addresses = $request->row['ip_addresses'];
                }
            }
            if (isset($request->checked)) {
                $apiKey->ip_auth = $request->checked;
            }
            $apiKey->status = 'true';
            $apiKey->save();

            return response()->json(['message' => 'API key created successfully'], 200);
        } catch (QueryException $e) {

            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {

            return response()->json(['error' => 'An error occurred: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}

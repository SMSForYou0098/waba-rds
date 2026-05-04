<?php

namespace App\Http\Controllers\Messaging;

use App\Http\Controllers\Controller;
use App\Models\Contact\ServerBlockNumber;
use App\Models\Contact\UserBlockNumber;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HandleBlockedNumber extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index($id)
    {
        $userBlocked = UserBlockNumber::where('user_id', $id)->get();
        if (Auth::check() && Auth::user()->hasRole('Admin')) {
            $serverBlocked = ServerBlockNumber::all();
            return response()->json(['userBlocked' => $userBlocked, 'serverBlocked' => $serverBlocked]);
        } else {
            return response()->json(['userBlocked' => $userBlocked]);
        }
    }
    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request)
    {
        try {
            if (Auth::check() && Auth::user()->hasRole('Admin') && $request->blockType === 'server') {
                return $this->storeServerBlokedNUmber($request);
            } else {
                return $this->storeUserBlokedNUmber($request);
            }
        } catch (QueryException $e) {
            $errorMessage = $e->getMessage();
            return response()->json(['status' => false, 'message' => 'Query Exception: ' . $errorMessage]);
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            return response()->json(['status' => false, 'message' => 'An error occurred while processing the request.' . $errorMessage]);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    protected function storeServerBlokedNUmber($request)
    {
        $block = new ServerBlockNumber();
        foreach ($request->number as $number) {
            $block = new ServerBlockNumber();
            $block->numbers = $number;
            $block->save();
        }
        return response()->json(['status' => true, 'message' => 'numbers stored successfully']);
    }
protected function storeUserBlokedNUmber($request)
    {
        // Check if $request->number is an array or a single value
        if (is_array($request->number)) {
            // Handle array of numbers
            $addedCount = 0;
            foreach ($request->number as $number) {
                // Check if the number already exists for this user
                $exists = UserBlockNumber::where('user_id', $request->user_id)
                    ->where('numbers', $number)
                    ->exists();

                if (!$exists) {
                    $block = new UserBlockNumber();
                    $block->user_id = $request->user_id;
                    $block->numbers = $number;
                    $block->save();
                    $addedCount++;
                }
            }
            $message = $addedCount > 0 ?
                "$addedCount numbers stored in user successfully" :
                "All numbers already exist in blocked list";

        } else {
            // Handle single number
            // Check if the number already exists for this user
            $exists = UserBlockNumber::where('user_id', $request->user_id)
                ->where('numbers', $request->number)
                ->exists();

            if (!$exists) {
                $block = new UserBlockNumber();
                $block->user_id = $request->user_id;
                $block->numbers = $request->number;
                $block->save();
                $message = "Number stored in user successfully";
            } else {
                $message = "Number already exists in blocked list";
            }
        }

        return response()->json(['status' => true, 'message' => $message]);
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
    public function updateUserBlockChatbotAccess(Request $request,$id)
    {
        $number = UserBlockNumber::findOrFail($id);
        $number->chatbot_access = $request->chatbot;
        $number->save();
        return response()->json(['status' => true, 'message' => 'Request updated successfully']);

    }
    public function updateServerBlokedNUmber(Request $request)
    {
        //
    }
    public function updateUserBlokedNUmber(Request $request)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, $id)
    {
        $deleteFor = $request->blockType;
        if($deleteFor == 'User'){
            $contact = UserBlockNumber::findOrFail($id);
            $contact->delete();
        }else{
            $contact = ServerBlockNumber::findOrFail($id);
            $contact->delete();
        }
        return response()->json(['status' => true, 'message' =>'Contact removed from blocked list']);
    }
}

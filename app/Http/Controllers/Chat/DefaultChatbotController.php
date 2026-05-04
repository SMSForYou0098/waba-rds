<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Chat\DefaultChatbot;
use Illuminate\Http\Request;

class DefaultChatbotController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request,$id)
    {
        try {
            $default = DefaultChatbot::where('user_id', $id)->firstOrFail();
            return response()->json(['default' => $default]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'No data found'], 404);
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request)
    {
        if (DefaultChatbot::where('user_id', $request->user_id)->exists()) {
            // Record exists for the user_id, update the fields
            $chatbot = DefaultChatbot::where('user_id', $request->user_id)->first();
            $chatbot->type = $request->type;
            $chatbot->template_name = $request->template;
            $chatbot->text = $request->text;
            $chatbot->status = 'Active';
            $chatbot->save();
            return response()->json(['message' => 'Default response updated successfully', 'chatbot' => $chatbot]);
        } else {
            // No record exists for the user_id, create a new one
            $chatbot = new DefaultChatbot();
            $chatbot->user_id = $request->user_id;
            $chatbot->type = $request->type;
            $chatbot->template_name = $request->template;
            $chatbot->text = $request->text;
            $chatbot->status = 'Active';
            $chatbot->save();
            return response()->json(['message' => 'New default response added successfully', 'chatbot' => $chatbot]);
        }

    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
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
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}

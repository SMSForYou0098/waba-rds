<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Chat\ChatbotAuth;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class ChatbotAuthController extends Controller
{
    public function store(Request $request)
    {
        try {
            $chatbot = new ChatbotAuth();
            $chatbot->keyword = json_encode($request->keyword);
            $chatbot->user_id = $request->user_id;
            $chatbot->chatbot_id = $request->chatbot_id;
            $chatbot->template_name = $request->template_name;
            $chatbot->custom_message = $request->custom_message;
            $chatbot->sr_no = $request->sr_no;
            $chatbot->reference_no = $request->ref_no;
            $chatbot->url_res_type = $request->url_res_type;
            $chatbot->send_res_type = $request->send_res_type;
            $chatbot->url = $request->url;
            $chatbot->res = $request->res;
            $chatbot->status = 'Active';
            $chatbot->save();

            return response()->json(['message' => 'Request submitted successfully', 'chatbot' => $chatbot]);
        } catch (QueryException $e) {
            // Log the exception or handle it accordingly
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            // Handle any other exceptions
            return response()->json(['error' => 'An error occurred: ' . $e->getMessage()], 500);
        }
    }
    public function edit(string $id)
    {
        $chatbotData = ChatbotAuth::findOrFail($id);
        return response()->json(['chatbot' => $chatbotData]);
    }
    public function update(Request $request)
    {
        try {
            $chatbot = ChatbotAuth::findOrFail($request->id);
            $chatbot->keyword = json_encode($request->keyword);
            $chatbot->user_id = $request->user_id;
            $chatbot->chatbot_id = $request->chatbot_id;
            $chatbot->template_name = $request->template_name;
            $chatbot->custom_message = $request->custom_message;
            $chatbot->sr_no = $request->sr_no;
            $chatbot->reference_no = $request->ref_no;
            $chatbot->url_res_type = $request->url_res_type;
            $chatbot->send_res_type = $request->send_res_type;
            $chatbot->url = $request->url;
            $chatbot->res = $request->res;
            $chatbot->status = 'Active';
            $chatbot->save();

            return response()->json(['message' => 'Request submitted successfully', 'chatbot' => $chatbot]);
        } catch (QueryException $e) {
            // Log the exception or handle it accordingly
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            // Handle any other exceptions
            return response()->json(['error' => 'An error occurred: ' . $e->getMessage()], 500);
        }
    }



}

<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Auth\ApiKey;
use App\Models\Chat\Chatbot;
use App\Models\Chat\ChatbotGroup;
use App\Models\Chat\ChatbotIdleTimer;
use App\Models\Chat\ChatbotMemory;
use App\Models\Chat\IdleMessageUser;
use App\Models\Report\Report;
use Illuminate\Http\Request;

class ChatBotController extends Controller
{
    public function updateGroupStatus(Request $request)
    {
        try {
            $userId = $request->user_id;
            $groupId = $request->group_id;
            $status = $request->status;
            // Find the group to activate
            $group = ChatbotGroup::findOrFail($groupId);

            // Begin transaction to ensure database consistency
            \DB::beginTransaction();

            // First, deactivate all groups for this user
            if ($status) {
                // If status is true, deactivate all other groups
                ChatbotGroup::where('user_id', $userId)->update(['status' => false]);
            }

            // Now set the requested group to active
            $group->status = $status;
            $group->save();

            \DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Chatbot group activated successfully',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            \DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Chatbot group not found to update the status',
            ], 404);
        } catch (\Exception $e) {
            \DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Failed to update chatbot group status',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function updateChatbotGroup(Request $request, $id)
    {
        // Validate the request
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'user_id' => 'required|integer',
            'description' => 'nullable|string',
            'chatbot_ids' => 'array',
            'chatbot_ids.*' => 'exists:chatbots,id',
        ]);

        try {
            // Find the chatbot group or return 404
            $group = ChatbotGroup::findOrFail($id);

            // Update group details
            $group->name = $validated['name'];
            $group->user_id = $validated['user_id'];
            $group->description = $validated['description'] ?? $group->description;
            $group->save();

            // Get current chatbots in this group
            $currentChatbotIds = Chatbot::where('group_id', $id)->pluck('id')->toArray();

            // Find chatbots to remove from the group
            $chatbotsToRemove = array_diff($currentChatbotIds, $validated['chatbot_ids']);
            if (!empty($chatbotsToRemove)) {
                Chatbot::whereIn('id', $chatbotsToRemove)->update(['group_id' => null]);
            }

            // Find chatbots to add to the group
            $chatbotsToAdd = array_diff($validated['chatbot_ids'], $currentChatbotIds);
            if (!empty($chatbotsToAdd)) {
                Chatbot::whereIn('id', $chatbotsToAdd)->update(['group_id' => $id]);
            }
            return response()->json([
                'status' => true,
                'message' => 'Chatbot group updated successfully',
                'data' => $group->load('chatbots')
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Chatbot group not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update chatbot group',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getChatbotGroups(Request $request, $userId)
    {
        try {
            $groups = ChatbotGroup::where('user_id', $userId)
                ->with([
                    'chatbots' => function ($query) {
                        $query->select('id', 'keyword', 'user_id', 'group_id', 'sr_no', 'status');
                    }
                ])
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Chatbot groups retrieved successfully',
                'data' => $groups
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve chatbot groups',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function createChatbotGroup(Request $request)
    {
        // Validate the request
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'user_id' => 'required|integer',
            'description' => 'nullable|string',
            'chatbot_ids' => 'array',
            'chatbot_ids.*' => 'exists:chatbots,id',
        ]);

        try {
            // Create the chatbot group
            $group = $this->storeChatbotGroup($validated['name'], $validated['user_id'], $validated['description'] ?? null);

            // Assign chatbots to the group
            $this->assignChatbotsToGroup($group->id, $validated['chatbot_ids']);
            $groupCount = ChatbotGroup::where('user_id', $validated['user_id'])->count();
            if ($groupCount === 1) {
                // This is the first group for this user, set status to true
                $group->status = true;
                $group->save();
            } else {
                // This is not the first group for this user, set status to false
                $group->status = false;
                $group->save();
            }
            return response()->json([
                'status' => true,
                'message' => 'Chatbot group created successfully',
                'data' => $group->load('chatbots')
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create chatbot group',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    private function storeChatbotGroup(string $name, $user_id, ?string $description = null)
    {
        // Assuming you have a ChatbotGroup model
        $group = new ChatbotGroup();
        $group->name = $name;
        $group->user_id = $user_id;
        $group->description = $description;
        $group->save();

        return $group;
    }
    private function assignChatbotsToGroup(int $groupId, array $chatbotIds)
    {
        // Alternative approach using a single query if your database structure supports it:
        Chatbot::whereIn('id', $chatbotIds)->update(['group_id' => $groupId]);
    }
    public function index(Request $request, $id, $groupId = null)
    {
        $query = Chatbot::where('user_id', $id);

        if (!empty($groupId) && $groupId !== 'null') {
            $query->where('group_id', $groupId);
        }

        $chatbots = $query
            ->orderBy('created_at', 'desc')
            ->orderBy('sr_no', 'desc')
            ->get();

        return response()->json(['status' => true, 'chatbot' => $chatbots]);
    }
    public function chatbotmap($id)
    {
        // Get the root parent (chatbot with no parent)
        $mainParent = Chatbot::where('user_id', $id)
            ->select(['id', 'keyword'])
            ->with([
                'children' => function ($query): void {
                    $query->select(['id', 'keyword', 'parent_id']);
                }
            ])
            ->whereNull('parent_id')
            ->first();

        if ($mainParent) {
            return response()->json(['status' => true, 'data' => $mainParent], 200);
        }

        return response()->json(['message' => 'No main parent found'], 404);
    }
    public function chekExistSerialNumber(Request $request)
    {
        if ($request->rq == 'edit') {
            $exists = Chatbot::where('user_id', $request->user_id)
                ->where('sr_no', $request->sr_no)
                ->where('id', '!=', $request->req_id) // Exclude the record with the provided ID
                ->exists();

            if ($exists) {
                return response()->json(['status' => true, 'exists' => $exists]);
            } else {
                return response()->json(['status' => false, 'exists' => $exists]);
            }
        } else {
            $exists = Chatbot::where('user_id', $request->user_id)
                ->where('sr_no', $request->sr_no)
                ->exists();
            if ($exists) {
                return response()->json(['status' => true, 'exists' => $exists]);
            } else {
                return response()->json(['status' => false, 'exists' => $exists]);
            }
        }
    }

    public function store(Request $request)
    {
        $maxSerialNo = Chatbot::where('user_id', $request->user_id)->max('sr_no');
        $newSerialNo = $maxSerialNo ? $maxSerialNo + 1 : 1;

        $chatbot = new Chatbot();
        $keywordAsString = json_encode($request->keyword);
        $chatbot->keyword = $keywordAsString;
        $chatbot->user_id = $request->user_id;
        $chatbot->sr_no = $newSerialNo;
        $chatbot->ref_no = $request->ref_no;
        $chatbot->parent_id = $request->parent_id;
      	$chatbot->list_item = $request->list_item;
        $chatbot->preset = $request->preset;

        $chatbot->custom_message_type = $request->custom_message_type;
        $chatbot->media_id = $request->media_id;

        $chatbot->store_keys = $request->store_keys;
        $chatbot->chatbot_state = $request->chatbot_state;
        $chatbot->chatbot_action = $request->chatbot_action;
		$chatbot->group_id = $request->group_id;
	
        $chatbot->header_value = $request->header_value;
        $chatbot->header_key = $request->header_key;
        $chatbot->header_required = $request->header_required;


        $chatbot->chatbot_type = $request->chatbot_type;
        $chatbot->reply_template = $request->reply_template;
        $chatbot->reply_template_language = $request->reply_template_language;
        $chatbot->reply_template_media = json_encode($request->reply_template_media);
        $chatbot->custom_type = $request->custom_type;
        $chatbot->reply_text = $request->reply_text;
        $chatbot->external_url = $request->external_url;
        $chatbot->url_action_type = $request->url_action_type;
        $chatbot->url_text = $request->url_text;
        $chatbot->url_xml = $request->url_xml;
        $chatbot->url_res = $request->url_res;
        $chatbot->url_json_key = $request->url_json_key;

        // for true
        $chatbot->json_true_key = $request->json_true_key;
        $chatbot->json_true_value = $request->json_true_value;
        $chatbot->json_true_outgoing_res = $request->json_true_outgoing_res;
        $chatbot->json_true_chatbot = $request->json_true_chatbot;
        $chatbot->json_true_template = $request->json_true_template;
        $chatbot->json_true_template_language = $request->json_true_template_language;
        $chatbot->json_true_template_media = json_encode($request->json_true_template_media);
        $chatbot->json_true_json_res = $request->json_true_json_res;
        $chatbot->json_true_custom_text = $request->json_true_custom_text;


        // for false
        $chatbot->json_false_key = $request->json_false_key;
        $chatbot->json_false_value = $request->json_false_value;
        $chatbot->json_false_outgoing_res = $request->json_false_outgoing_res;
        $chatbot->json_false_chatbot = $request->json_false_chatbot;
        $chatbot->json_false_template = $request->json_false_template;
        $chatbot->json_false_template_language = $request->json_false_template_language;
        $chatbot->json_false_template_media = json_encode($request->json_false_template_media);
        $chatbot->json_false_json_res = $request->json_false_json_res;
        $chatbot->json_false_custom_text = $request->json_false_custom_text;
        $chatbot->json_true_list_key = $request->json_true_list_key;
        $chatbot->json_false_list_key = $request->json_false_list_key;

        if ($request->filled('reply_template_api') && $request->input('reply_template_api') !== 'undefined') {
            $chatbot->reply_template_api = $request->input('reply_template_api');
        }

        if ($request->filled('json_false_template_api') && $request->input('json_false_template_api') !== 'undefined') {
            $chatbot->json_false_template_api = $request->input('json_false_template_api');
        }

        if ($request->filled('json_true_template_api') && $request->input('json_true_template_api') !== 'undefined') {
            $chatbot->json_true_template_api = $request->input('json_true_template_api');
        }

        $chatbot->status = 'Active';
        $chatbot->save();
        return response()->json(['message' => 'chatbot created successfully', 'chatbot' => $chatbot]);
    }
    public function edit(string $id)
    {
        $chatbotData = Chatbot::findOrFail($id);
        return response()->json(['chatbot' => $chatbotData]);
    }

    public function update(Request $request)
    {

        // return response()->json([ 'chatbot' => $request->all()]);
        $chatbot = Chatbot::findOrFail($request->id);
        $keywordAsString = json_encode($request->keyword);
        $chatbot->keyword = $keywordAsString;
        $chatbot->user_id = $request->user_id;
        $chatbot->ref_no = $request->ref_no;
        $chatbot->parent_id = $request->parent_id;
        $chatbot->preset = $request->preset;
      	$chatbot->list_item = $request->list_item;
        $chatbot->chatbot_type = $request->chatbot_type;
        $chatbot->reply_template = $request->reply_template;
        $chatbot->reply_template_language = $request->reply_template_language;
        $chatbot->reply_template_media = json_encode($request->reply_template_media);
		$chatbot->group_id = $request->group_id;
      
        $chatbot->custom_message_type = $request->custom_message_type;
        $chatbot->media_id = $request->media_id;

        $chatbot->custom_type = $request->custom_type;
        $chatbot->reply_text = $request->reply_text;
        $chatbot->external_url = $request->external_url;
        $chatbot->url_action_type = $request->url_action_type;
        $chatbot->url_text = $request->url_text;
        $chatbot->url_xml = $request->url_xml;
        $chatbot->url_res = $request->url_res;
        $chatbot->url_json_key = $request->url_json_key;
        // for true
        $chatbot->json_true_key = $request->json_true_key;
        $chatbot->json_true_value = $request->json_true_value;
        $chatbot->json_true_outgoing_res = $request->json_true_outgoing_res;
        $chatbot->json_true_chatbot = $request->json_true_chatbot;
        $chatbot->json_true_template = $request->json_true_template;
        $chatbot->json_true_template_language = $request->json_true_template_language;
        $chatbot->json_true_template_media = json_encode($request->json_true_template_media);
        $chatbot->json_true_json_res = $request->json_true_json_res;
        $chatbot->json_true_custom_text = $request->json_true_custom_text;

        // for false
        $chatbot->json_false_key = $request->json_false_key;
        $chatbot->json_false_value = $request->json_false_value;
        $chatbot->json_false_outgoing_res = $request->json_false_outgoing_res;
        $chatbot->json_false_chatbot = $request->json_false_chatbot;
        $chatbot->json_false_template = $request->json_false_template;
        $chatbot->json_false_template_language = $request->json_false_template_language;
        $chatbot->json_false_template_media = json_encode($request->json_false_template_media);
        $chatbot->json_false_json_res = $request->json_false_json_res;
        $chatbot->json_false_custom_text = $request->json_false_custom_text;

        $chatbot->json_true_list_key = $request->json_true_list_key;
        $chatbot->json_false_list_key = $request->json_false_list_key;
        if ($request->filled('reply_template_api') && $request->input('reply_template_api') !== 'undefined') {
            $chatbot->reply_template_api = $request->input('reply_template_api');
        }

        if ($request->filled('json_false_template_api') && $request->input('json_false_template_api') !== 'undefined') {
            $chatbot->json_false_template_api = $request->input('json_false_template_api');
        }

        if ($request->filled('json_true_template_api') && $request->input('json_true_template_api') !== 'undefined') {
            $chatbot->json_true_template_api = $request->input('json_true_template_api');
        }

        $chatbot->status = 'Active';
        $chatbot->save();
        return response()->json(['message' => 'chatbot updated successfully', 'chatbot' => $chatbot]);
    }

    public function destroy(string $id)
    {
        $contact = Chatbot::findOrFail($id);
        $contact->delete();
        return response()->json(['status' => true, 'message' => 'Request deleted successfully']);
    }
    public function deleteChatbotGroup($groupId)
    {
        try {
            // Find the group or fail
            $group = ChatbotGroup::findOrFail($groupId);

            // Set group_id to null for all chatbots in this group
            Chatbot::where('group_id', $groupId)->update(['group_id' => null]);

            // Delete the group
            $group->delete();

            return response()->json([
                'status' => true,
                'message' => 'Chatbot group deleted successfully and chatbots unassigned.'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Chatbot group not found.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete chatbot group.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function getKeywordsByUser($userId)
    {
        $chatbots = Chatbot::where('user_id', $userId)->get();
        $allKeywords = [];
        foreach ($chatbots as $chatbot) {
            $keywordsArray = json_decode($chatbot->keyword, true);
            if (is_array($keywordsArray)) {
                $allKeywords = array_merge($allKeywords, $keywordsArray);
            }
        }
        return response()->json(['status' => true, 'keywords' => array_values(array_unique($allKeywords))]);
    }

    public function rearrangeSerialNumbers()
    {
        // Fetch all data ordered by user_id and created_at
        $chatbots = Chatbot::orderBy('user_id')
            ->orderBy('created_at')
            ->get();

        // Initialize variables to keep track of the current serial number for each user
        $currentUserId = null;
        $serialNumber = 1;

        foreach ($chatbots as $chatbot) {
            // If the user_id changes, reset the serial number
            if ($chatbot->user_id !== $currentUserId) {
                $currentUserId = $chatbot->user_id;
                $serialNumber = 1; // Reset serial number for the new user
            }

            // Update the serial_no in the database
            $chatbot->update(['sr_no' => $serialNumber]);

            // Increment the serial number for the next record
            $serialNumber++;
        }

        return response()->json(['status' => 'success', 'message' => 'Serial numbers rearranged successfully']);
    }

    public function getIdealTimerById($id)
    {
        try {
            $idealTimer = ChatbotIdealTimer::where('user_id', $id)->first();
            if ($idealTimer) {
                return response()->json([
                    'status' => true,
                    'data' => $idealTimer
                ]);
            } else {
                return response()->json([
                    'status' => true,
                    'message' => 'Ideal Timer not found'
                ], 404); // 404 Not Found
            }

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve Ideal Timer',
                'error' => $e->getMessage()
            ], 500); // 500 Internal Server Error
        }
    }
    public function storeIdealTimer(Request $request)
    {
        try {
            // Check if a record already exists for the given user_id
            $idealTimer = ChatbotIdealTimer::where('user_id', $request->user_id)->first();

            if ($idealTimer) {
                // Update the existing record
                $idealTimer->status = $request->status;
                $idealTimer->minutes = $request->minutes;
                $idealTimer->message = $request->message;
                $idealTimer->template = $request->template;
                $idealTimer->reset_on_message = $request->reset_on_message;
                $idealTimer->save();

                return response()->json([
                    'status' => true,
                    'message' => 'Ideal Timer updated successfully',
                    'data' => $idealTimer
                ], 200); // 200 OK status code
            } else {
                // Create a new record
                $idealTimer = new ChatbotIdealTimer();
                $idealTimer->user_id = $request->user_id;
                $idealTimer->status = $request->status;
                $idealTimer->minutes = $request->minutes;
                $idealTimer->message = $request->message;
                $idealTimer->template = $request->template;
                $idealTimer->save();

                return response()->json([
                    'status' => true,
                    'message' => 'Ideal Timer created successfully',
                    'data' => $idealTimer
                ], 201); // 201 Created status code
            }

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to store Ideal Timer',
                'error' => $e->getMessage()
            ], 500); // 500 Internal Server Error
        }
    }
    public function ActiveUserIdleSession(Request $request)
    {
        try {
            $apiKey = $request->query('apiKey');
            $number = $request->query('number');

            // Validate the number format (10 or 12 digits)
            if (!preg_match('/^\d{10}$|^\d{12}$/', $number)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid number. It must be 10 or 12 digits.'
                ]);
            }

            // Validate 12-digit numbers start with '91'
            if (strlen($number) === 12 && substr($number, 0, 2) !== '91') {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid number. 12-digit numbers must start with "91".'
                ]);
            }

            // Normalize the number
            $normalizedNumber = strlen($number) === 10 ? '91' . $number : $number;

            // Validate the API key
            $apiKeyRecord = ApiKey::where('key', $apiKey)->where('status', 'true')->first();
            if (!$apiKeyRecord) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid or inactive API key.'
                ]);
            }

            // Create the IdleMessageUser record
            $user = $apiKeyRecord->user;
            $deletedRecords = IdleMessageUser::where(['number' => $normalizedNumber, 'user_id' => $user->id])->get();
            // Check if any records were found
            if ($deletedRecords->isEmpty()) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'No records found to delete.',
                    'deleted_records' => []
                ], 200);
            }
            // Delete the records
            IdleMessageUser::where(['number' => $normalizedNumber, 'user_id' => $user->id])->delete();

            return response()->json([
                'status' => true,
                'message' => 'Records deleted successfully.',
                'deleted_records' => $deletedRecords
            ], 200);
        } catch (\Exception $e) {
            // Handle exceptions and return an error response
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while processing your request.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function IdleUserSession(Request $request)
    {
        try {
            $apiKey = $request->query('apiKey');
            $number = $request->query('number');

            // Validate the number format
            if (!preg_match('/^\d{10}$|^\d{12}$/', $number)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid number. It must be 10 or 12 digits.'
                ]);
            }

            if (strlen($number) === 12 && substr($number, 0, 2) !== '91') {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid number. 12-digit numbers must start with "91".'
                ]);
            }

            // Normalize the number
            $normalizedNumber = strlen($number) === 10 ? '91' . $number : $number;

            // Check if API key is valid and active
            $apiKeyRecord = ApiKey::where('key', $apiKey)->where('status', 'true')->first();

            if (!$apiKeyRecord) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid or inactive API key.'
                ]);
            }

            $user = $apiKeyRecord->user;
            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User associated with API key not found.'
                ]);
            }

            $isUserSession = IdleMessageUser::where('user_id', $user->id)
                ->where('number', $normalizedNumber)
                ->exists();

            $isUserReport = Report::where('display_phone_number', $user->whatsapp_number)
                ->where('wa_id', $normalizedNumber)
                ->exists();
            //	return [$isUserReport];
            if ($isUserSession || !$isUserReport) {
                return response()->json([
                    'status' => false,
                    'message' => 'User session expired.'
                ]);
            } else {
                return response()->json([
                    'status' => true,
                    'message' => 'User Session is active.'
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while processing your request. Please try again later.' . $e->getMessage()
            ]);
        }
    }
    public function chatbotMemoryDataVerify(Request $request)
    {
        try {
            $apiKey = $request->query('apiKey');
            $number = $request->query('number');
            $key = $request->query('key');

            // Validate the number format
            if (!preg_match('/^\d{10}$|^\d{12}$/', $number)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid number. It must be 10 or 12 digits.'
                ]);
            }

            if (strlen($number) === 12 && substr($number, 0, 2) !== '91') {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid number. 12-digit numbers must start with "91".'
                ]);
            }

            // Normalize the number
            $normalizedNumber = strlen($number) === 10 ? '91' . $number : $number;

            // Check if API key is valid and active
            $apiKeyRecord = ApiKey::where('key', $apiKey)->where('status', 'true')->first();

            if (!$apiKeyRecord) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid or inactive API key.'
                ]);
            }

            $user = $apiKeyRecord->user;
            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User associated with API key not found.'
                ]);
            }

            $isData = ChatbotMemory::where('user_id', $user->id)
                ->where('mobile_number', $normalizedNumber)
                ->where('key', $key)
                ->exists();

            //	return [$isUserReport];
            if ($isData) {
                return response()->json([
                    'status' => true,
                    'message' => 'Data exists for this record.'
                ]);
            } else {
                return response()->json([
                    'status' => false,
                    'message' => "No data found for this record."
                ]);
            }

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while processing your request. Please try again later.' . $e->getMessage()
            ]);
        }
    }

    public function copyRequests(Request $request)
    {
        $request->validate([
            'from_user_id' => 'required|exists:users,id',
            'to_user_id' => 'required|exists:users,id|different:from_user_id',
        ]);

        $fromUserId = $request->from_user_id;
        $toUserId = $request->to_user_id;

        // Fetch all chatbot records of the "from_user"
        $chatbotRecords = Chatbot::where('user_id', $fromUserId)->get();

        // Clone each record and assign the new user_id
        foreach ($chatbotRecords as $record) {
            $newRecord = $record->replicate(); // Copy the record
            $newRecord->user_id = $toUserId;  // Assign new user ID
            $newRecord->save(); // Save new record
        }

        return response()->json([
            'message' => "Successfully copied " . count($chatbotRecords) . " requests from user $fromUserId to user $toUserId",
        ]);
    }

}

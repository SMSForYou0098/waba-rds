<?php

namespace App\Http\Controllers\Contact;

use App\Http\Controllers\Controller;
use App\Models\Contact\Group;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class GroupController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index($id)
    {
        $groups = Group::where('user_id', $id)
               ->with('contacts')
               ->withCount('contacts')
               ->get();
        return response()->json(['groups' => $groups]);
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
        try {

            // Create a new Campaign
            $newRecord = new Group();
            $newRecord->user_id = $request->user_id;
            $newRecord->name = $request->name;
            $newRecord->description = $request->description;
            $newRecord->save();

            return response()->json(['status' => true, 'message' => 'Group stored successfully']);
        } catch (QueryException $exception) {
            // Handle database query exceptions
            return response()->json(['message' => 'Error: ' . $exception->getMessage()], 500);
        } catch (\Exception $exception) {
            // Handle other exceptions
            return response()->json(['message' => 'Error: ' . $exception->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {

        $groups = Group::where('id',$id)
               ->with('contacts')
            //    ->withCount('contacts')
               ->first();
            //    $contacts = $groups->contacts();
               return response()->json(['groups' => $groups]);
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
        $contact = Group::findOrFail($id);
        $contact->delete();
        return response()->json(['status' => true, 'message' => 'Group deleted successfully']);
    }
}

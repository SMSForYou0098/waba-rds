<?php

namespace App\Http\Controllers\Contact;

use App\Http\Controllers\Controller;
use App\Models\Contact\Contact;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index($id)
    {
        $contacts = Contact::where('Group_id', $id)->get();
        return response()->json(['contacts' => $contacts]);
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

            // Create a new Campaign
            $newRecord = new Contact();
            $newRecord->group_id = $request->group_id;
            $newRecord->name = $request->name;
            $newRecord->number = $request->number;
            $newRecord->email = $request->email;
            $newRecord->location = $request->location;
            $newRecord->save();

            return response()->json(['status' => true, 'message' => 'Contact stored successfully']);
        } catch (QueryException $exception) {
            // Handle database query exceptions
            return response()->json(['message' => 'Error: ' . $exception->getMessage()], 500);
        } catch (\Exception $exception) {
            // Handle other exceptions
            return response()->json(['message' => 'Error: ' . $exception->getMessage()], 500);
        }
    }

     public function ImportContact(Request $request, $id)
    {
        try {
            $excelData = $request->importedData;
            foreach ($excelData as $row) {
                // Ensure the row has at least the expected number of columns (0 - name, 1 - number, 2 - email, 3 - location)
                if (!empty($row)) {
                    $name = isset($row[0]) ? $row[0] : null;
                    $number = isset($row[1]) ? $row[1] : null;
                    $email = isset($row[2]) ? $row[2] : null;
                    $location = isset($row[3]) ? $row[3] : null;

                    // Check if a contact with the same details already exists in the group
                    $existingRecord = Contact::where('group_id', $id)
                        ->where('name', $name)
                        ->where('number', $number)
                        ->where('email', $email)
                        ->where('location', $location)
                        ->first();

                    if (!$existingRecord) {
                        // Create a new contact if it doesn't exist
                        $newRecord = new Contact();
                        $newRecord->group_id = $id;
                        $newRecord->name = $name;
                        $newRecord->number = $number;
                        $newRecord->email = $email;
                        $newRecord->location = $location;
                        $newRecord->save();
                    }
                }
            }

            return response()->json(['status' => true, 'message' => $excelData]);
        } catch (\Exception $exception) {
            // Handle any exceptions
            return response()->json(['message' => 'Error: ' . $exception->getMessage()], 500);
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
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $contact = Contact::findOrFail($id);
        $contact->delete();
        return response()->json(['status' => true, 'message' => 'Conact deleted successfully']);
    }
    public function destroyMultiple(Request $request)
    {
        $idsToDelete = $request->input('ids');

        try {
            Contact::destroy($idsToDelete);
            return response()->json(['status' => true, 'message' => 'Contacts deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Error deleting contacts'], 500);
        }
    }
}

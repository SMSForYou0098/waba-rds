<?php

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\Template\ListMessagePreset;
use Illuminate\Http\Request;

class ListMessagePresetController extends Controller
{
    public function index($id)
    {
        $presets = ListMessagePreset::where('user_id', $id)->get();
        return response()->json(['data'=>$presets,'status'=>true],200);
    }
    public function store(Request $request)
    {
        try {
            // Store the data
            $preset = new ListMessagePreset();
            $preset->user_id = $request->user_id;
            $preset->name = $request->name;
            $preset->header = $request->header;
            $preset->body = $request->body;
            $preset->footer = $request->footer ?? null;
            $preset->button_text = $request->button_text ?? null;
            $preset->rows = is_array($request->rows) ? json_encode($request->rows) : null;
            $preset->object = json_encode($request->object);
            $preset->save();

            return response()->json(['message' => 'List Message Preset created successfully!', 'data' => $preset,'status'=>true], 201);

        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while creating the List Message Preset.', 'error' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        $preset = ListMessagePreset::find($id);

        if (!$preset) {
            return response()->json(['message' => 'Preset not found.'], 404);
        }

        return response()->json($preset);
    }
    public function update(Request $request, $id)
    {
        $preset = ListMessagePreset::find($id);
        $preset->header = $request->header;
        $preset->body = $request->body;
      	$preset->name = $request->name;
        $preset->footer = $request->footer;
        $preset->button_text = $request->button_text;
        $preset->rows = $request->rows;
        $preset->object = json_encode($request->object);
        $preset->save();

        return response()->json(['message' => 'List Message Preset updated successfully!', 'data' => $preset,'status'=>true]);
    }
    public function destroy($id)
    {
        $preset = ListMessagePreset::find($id);
        if (!$preset) {
            return response()->json(['message' => 'Preset not found.'], 404);
        }
        $preset->delete();
        return response()->json(['message' => 'List Message Preset deleted successfully.','status'=>true]);
    }
}
